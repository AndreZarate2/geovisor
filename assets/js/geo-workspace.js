(function () {
  'use strict';

  const el = id => document.getElementById(id);
  const workspace = el('geoWorkspace');
  if (!workspace) return;

  const ui = {
    close:el('geoWorkspaceClose'), status:el('geoWorkspaceStatus'), file:el('geoWorkspaceFile'),
    projectName:el('geoProjectName'), projectSave:el('geoProjectSave'), projectSelect:el('geoProjectSelect'),
    projectLoad:el('geoProjectLoad'), tree:el('geoWorkspaceTree'), treeRefresh:el('geoTreeRefresh'),
    allOn:el('geoAllOn'), allOff:el('geoAllOff'), inspector:workspace.querySelector('.geo-workspace-right'),
    inspectorClose:el('geoInspectorClose'), inspectorTitle:el('geoInspectorTitle'),
    inspectorSubtitle:el('geoInspectorSubtitle'), inspectorBody:el('geoInspectorBody'),
    drawHint:el('geoDrawHint'), drawFinish:el('geoDrawFinish'), drawUndo:el('geoDrawUndo'),
    addScene:el('geoAddScene'), presentStart:el('geoPresentStart'), presentBar:el('geoPresentationBar'),
    presentPrev:el('geoPresentPrev'), presentNext:el('geoPresentNext'), presentStop:el('geoPresentStop'),
    presentCounter:el('geoPresentCounter'), sceneTitle:el('geoSceneTitle'),
  };

  const state = {
    bridge:null, open:false, pendingOpen:false, selected:'', dirty:false, projectId:'',
    projectDocuments:[], scenes:[], sceneIndex:0, drawing:{mode:'', points:[], draft:null},
    elevation:new Map(), elevationToken:0, elevationMarker:null, folderIndex:new Map(),
    folderOpen:new Set(), folderTouched:new Set(),
    units:{area:'km2', distance:'km', elevation:'m'},
  };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  }
  function f2(value, digits=2) {
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString('es-PE', {minimumFractionDigits:digits, maximumFractionDigits:digits}) : '—';
  }
  function setStatus(text) { if (ui.status) ui.status.textContent = text; }
  function notify(message, type='in', ms=3800) {
    if (state.bridge?.toast) state.bridge.toast(message, type, ms);
  }
  function markDirty(value=true) {
    state.dirty = !!value;
    const count = state.bridge?.state?.features?.length || 0;
    const base = state.projectId ? 'Proyecto guardado' : 'Proyecto temporal';
    setStatus(`${base}${state.dirty ? ' · cambios pendientes' : ''} · ${count} elementos GEO`);
  }

  function open() {
    if (!state.bridge) { state.pendingOpen = true; return; }
    state.open = true;
    workspace.classList.add('open');
    workspace.setAttribute('aria-hidden', 'false');
    document.body.classList.add('geo-workspace-open');
    el('map-wrap')?.classList.add('geo-workspace-open');
    el('tempGeoPanel')?.classList.remove('open');
    el('dockTempGeo')?.classList.add('on');
    state.bridge.map.invalidateSize({pan:false});
    renderTree();
    loadProjectList();
    if (state.selected) inspectFeature(state.selected);
    markDirty(state.dirty);
  }
  function close() {
    stopPresentation();
    cancelDraw();
    clearElevationMarker();
    state.open = false;
    workspace.classList.remove('open');
    workspace.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('geo-workspace-open', 'geo-presenting', 'geo-drawing');
    el('map-wrap')?.classList.remove('geo-workspace-open');
    el('dockTempGeo')?.classList.remove('on');
    state.bridge?.map?.invalidateSize({pan:false});
  }
  window.CVCGeoWorkspace = {open, close, isOpen:() => state.open};

  function attachBridge(bridge) {
    if (!bridge?.map) return;
    state.bridge = bridge;
    bridge.map.on('click', onMapClick);
    bridge.map.on('dblclick', onMapDoubleClick);
    if (state.pendingOpen) { state.pendingOpen = false; open(); }
  }
  if (window.CVC_GEO_BRIDGE) attachBridge(window.CVC_GEO_BRIDGE);
  document.addEventListener('cvc:map-ready', event => attachBridge(event.detail?.bridge));
  document.addEventListener('cvc:geo-feature-selected', event => {
    state.selected = event.detail?.id || '';
    if (state.open) { renderTree(); inspectFeature(state.selected); }
  });

  function geometryLabel(feature) {
    return feature?.geometry?.type === 'Polygon' ? 'Polígono' : feature?.geometry?.type === 'LineString' ? 'Ruta' : 'Punto';
  }
  function featureId(feature, index=0) {
    if (!feature.id) feature.id = `geo-${Date.now()}-${index}-${Math.random().toString(36).slice(2,7)}`;
    return feature.id;
  }
  function pathParts(value) {
    return String(value || '').split(/\s*\/\s*/).map(part => part.trim()).filter(Boolean);
  }
  function sourceRoot(feature) {
    const source = String(feature?.properties?.source_file || '').trim();
    if (source) return pathParts(source)[0] || source;
    return String(feature?.properties?.document || 'Elementos GEO').trim() || 'Elementos GEO';
  }
  function comparableFolderName(value) {
    return String(value || '').replace(/\.(kmz|kml|geojson|json)$/i, '').trim().toLocaleLowerCase('es');
  }
  function hierarchyParts(feature) {
    const root = sourceRoot(feature);
    const folders = pathParts(feature?.properties?.folder_path || feature?.properties?.document || 'Sin carpeta');
    if (folders.length && comparableFolderName(folders[0]) === comparableFolderName(root)) folders.shift();
    return [root, ...folders].filter(Boolean).filter((part, index, all) => index === 0 || comparableFolderName(part) !== comparableFolderName(all[index - 1]));
  }
  function makeTree(features) {
    const root = {key:'geo-root', name:'', children:new Map(), features:[], ids:[]};
    features.forEach((feature, index) => {
      featureId(feature, index);
      let cursor = root;
      hierarchyParts(feature).forEach(name => {
        const key = `${cursor.key}/${encodeURIComponent(name)}`;
        if (!cursor.children.has(name)) cursor.children.set(name, {key, name, children:new Map(), features:[], ids:[]});
        cursor = cursor.children.get(name);
      });
      cursor.features.push(feature);
    });
    const collect = node => {
      node.ids = [...node.features.map(feature => String(feature.id)), ...[...node.children.values()].flatMap(collect)];
      if (node.key !== 'geo-root') state.folderIndex.set(node.key, node.ids);
      return node.ids;
    };
    state.folderIndex.clear();
    collect(root);
    return root;
  }
  function featureRow(feature) {
    const meta = state.bridge.featureMeta(feature);
    const id = feature.id;
    return `<div class="geo-tree-row ${state.selected === id ? 'selected' : ''}" data-geo-row="${esc(id)}">
      <input type="checkbox" data-geo-visible="${esc(id)}" ${meta.visible !== false ? 'checked' : ''} aria-label="Mostrar ${esc(feature.name || geometryLabel(feature))}">
      <label data-geo-select="${esc(id)}"><strong>${esc(feature.name || geometryLabel(feature))}</strong><small>${esc(geometryLabel(feature))}</small></label>
      <button type="button" data-geo-zoom="${esc(id)}" title="Acercar">&#8982;</button>
    </div>`;
  }
  function renderTreeNode(node, depth=0) {
    const visibleCount = node.ids.filter(id => {
      const feature = state.bridge.featureById(id);
      return feature && state.bridge.featureMeta(feature).visible !== false;
    }).length;
    const checked = node.ids.length > 0 && visibleCount === node.ids.length;
    const indeterminate = visibleCount > 0 && visibleCount < node.ids.length;
    const open = state.folderTouched.has(node.key) ? state.folderOpen.has(node.key) : depth < 2;
    const children = [...node.children.values()].sort((a,b) => a.name.localeCompare(b.name, 'es')).map(child => renderTreeNode(child, depth + 1)).join('');
    const rows = node.features.slice().sort((a,b) => String(a.name || '').localeCompare(String(b.name || ''), 'es')).map(featureRow).join('');
    return `<details class="geo-tree-node depth-${Math.min(depth, 5)}" data-geo-node="${esc(node.key)}" ${open ? 'open' : ''}>
      <summary>
        <input type="checkbox" data-geo-folder="${esc(node.key)}" ${checked ? 'checked' : ''} data-indeterminate="${indeterminate ? '1' : '0'}" aria-label="Mostrar toda la carpeta ${esc(node.name)}">
        <span class="geo-folder-icon" aria-hidden="true">&#128193;</span>
        <span class="geo-folder-name" title="${esc(node.name)}">${esc(node.name)}</span>
        <span class="geo-tree-count">${visibleCount}/${node.ids.length}</span>
      </summary>
      <div class="geo-tree-children">${children}${rows}</div>
    </details>`;
  }
  function renderTree() {
    if (!ui.tree || !state.bridge) return;
    const features = state.bridge.state.features || [];
    const tree = makeTree(features);
    const geoGroups = [...tree.children.values()].sort((a,b) => a.name.localeCompare(b.name, 'es')).map(node => renderTreeNode(node, 0)).join('');

    const platformRows = [...document.querySelectorAll('.lyr[data-id]')].slice(0, 160).map(layer => {
      const id = layer.dataset.id || '';
      const name = layer.querySelector('.lyr-name')?.textContent?.trim() || id;
      const on = layer.classList.contains('on');
      return `<div class="geo-tree-row"><input type="checkbox" data-platform-layer="${esc(id)}" ${on ? 'checked' : ''}><label data-platform-layer-label="${esc(id)}"><strong>${esc(name)}</strong><small>${esc(layer.dataset.tipo || 'capa de plataforma')}</small></label><button type="button" data-platform-layer-label="${esc(id)}">&#9673;</button></div>`;
    }).join('');

    ui.tree.innerHTML = `${geoGroups || '<div class="geo-empty-state">Abra un KML, KMZ o GeoJSON, o dibuje un elemento.</div>'}
      <details class="geo-tree-group"><summary><span>&#9638;</span><span>Capas de la plataforma</span><span class="geo-tree-count">${document.querySelectorAll('.lyr.on[data-id]').length} activas</span></summary>${platformRows || '<div class="geo-empty-state">No hay capas registradas.</div>'}</details>`;
    ui.tree.querySelectorAll('[data-geo-folder][data-indeterminate="1"]').forEach(input => { input.indeterminate = true; });
  }

  function togglePlatformLayer(id) {
    const original = [...document.querySelectorAll('.lyr[data-id]')].find(node => node.dataset.id === id);
    original?.click();
    setTimeout(renderTree, 200);
  }

  async function loadGeoFile(file) {
    if (!file || !state.bridge) return;
    const form = new FormData();
    if (state.bridge.csrf) form.append('_csrf', state.bridge.csrf);
    form.append('kml_file', file);
    setStatus(`Leyendo ${file.name}…`);
    try {
      const response = await fetch('api/temp_kml.php', {method:'POST', body:form});
      const data = await state.bridge.readJsonResponse(response);
      if (!data.ok) throw new Error(data.message || 'No se pudo leer el archivo.');
      const imported = Array.isArray(data.features) ? data.features : [];
      if (!imported.length) throw new Error('El archivo no contiene geometrías compatibles.');
      const existing = state.bridge.state.features || [];
      state.projectDocuments.push(...(Array.isArray(data.documents) ? data.documents : [{name:file.name, features:imported.length}]));
      state.bridge.drawFeatures([...existing, ...imported], {resetMeta:false, fit:true});
      markDirty(true);
      renderTree();
      const extra = data.kml_documents > 1 ? ` en ${data.kml_documents} documentos KML` : '';
      notify(`${imported.length} elementos cargados${extra}. Permanecen temporales hasta guardar el proyecto.`, data.truncated ? 'wa' : 'ok', 6000);
    } catch (error) {
      notify('No se pudo abrir el archivo GEO: ' + error.message, 'er', 6500);
      markDirty(state.dirty);
    } finally {
      ui.file.value = '';
    }
  }

  function startDraw(mode) {
    if (!state.bridge) return;
    cancelDraw();
    state.drawing.mode = mode;
    document.body.classList.add('geo-drawing');
    workspace.querySelectorAll('[data-geo-draw]').forEach(button => button.classList.toggle('active', button.dataset.geoDraw === mode));
    ui.drawHint.hidden = false;
    ui.drawHint.textContent = mode === 'point'
      ? 'Haga clic en el mapa para colocar el punto.'
      : `Haga clic para añadir vértices. Use “Finalizar” o doble clic para terminar ${mode === 'polygon' ? 'el polígono' : 'la ruta'}.`;
    state.bridge.map.doubleClickZoom.disable();
  }
  function cancelDraw() {
    if (!state.bridge) return;
    if (state.drawing.draft) state.bridge.map.removeLayer(state.drawing.draft);
    state.drawing = {mode:'', points:[], draft:null};
    document.body.classList.remove('geo-drawing');
    workspace.querySelectorAll('[data-geo-draw]').forEach(button => button.classList.remove('active'));
    if (ui.drawHint) ui.drawHint.hidden = true;
    state.bridge.map.doubleClickZoom.enable();
  }
  function onMapClick(event) {
    if (!state.open || !state.drawing.mode) return;
    const point = [Number(event.latlng.lat.toFixed(7)), Number(event.latlng.lng.toFixed(7))];
    if (state.drawing.mode === 'point') {
      addDrawnFeature('Point', [point]);
      cancelDraw();
      return;
    }
    state.drawing.points.push(point);
    updateDraft();
  }
  function onMapDoubleClick(event) {
    if (!state.open || !state.drawing.mode || state.drawing.mode === 'point') return;
    event.originalEvent?.preventDefault?.();
    finishDraw();
  }
  function updateDraft() {
    const points = state.drawing.points.map(point => [point[0], point[1]]);
    if (state.drawing.draft) state.bridge.map.removeLayer(state.drawing.draft);
    if (!points.length) return;
    state.drawing.draft = state.drawing.mode === 'polygon'
      ? L.polygon(points, {color:'#f97316', weight:2, dashArray:'5 5', fillOpacity:.10}).addTo(state.bridge.map)
      : L.polyline(points, {color:'#f97316', weight:3, dashArray:'5 5'}).addTo(state.bridge.map);
  }
  function finishDraw() {
    const mode = state.drawing.mode;
    const points = [...state.drawing.points];
    if (mode === 'line' && points.length < 2) return notify('Añada al menos dos puntos para una ruta.', 'wa');
    if (mode === 'polygon' && points.length < 3) return notify('Añada al menos tres puntos para un polígono.', 'wa');
    if (!mode) return;
    addDrawnFeature(mode === 'polygon' ? 'Polygon' : 'LineString', points);
    cancelDraw();
  }
  function addDrawnFeature(type, coordinates) {
    const count = (state.bridge.state.features || []).filter(item => item.properties?.drawn_in_workspace).length + 1;
    const feature = {
      id:`geo-drawn-${Date.now()}-${Math.random().toString(36).slice(2,7)}`,
      name:`${type === 'Polygon' ? 'Polígono' : type === 'LineString' ? 'Ruta' : 'Punto'} ${count}`,
      geometry:{type, coordinates},
      properties:{source_file:'Dibujo en Taller GEO', folder_path:'Dibujos', drawn_in_workspace:true},
    };
    state.bridge.appendFeatures([feature], true);
    state.selected = feature.id;
    markDirty(true);
    renderTree();
    inspectFeature(feature.id);
  }

  function pointsFor(feature) { return state.bridge?.points(feature) || []; }
  function distanceKm(a, b) { return L.latLng(a[0], a[1]).distanceTo(L.latLng(b[0], b[1])) / 1000; }
  function closedPerimeter(points) {
    let km = state.bridge.lengthKm(points);
    if (points.length > 2) km += distanceKm(points[points.length - 1], points[0]);
    return km;
  }
  function bearing(points) {
    if (points.length < 2) return null;
    const [lat1, lon1] = points[0].map(value => value * Math.PI / 180);
    const [lat2, lon2] = points[points.length - 1].map(value => value * Math.PI / 180);
    const y = Math.sin(lon2-lon1) * Math.cos(lat2);
    const x = Math.cos(lat1)*Math.sin(lat2) - Math.sin(lat1)*Math.cos(lat2)*Math.cos(lon2-lon1);
    return (Math.atan2(y,x) * 180 / Math.PI + 360) % 360;
  }
  const unitDefinitions = {
    area:[
      {value:'km2', label:'km²', factor:1, digits:3},
      {value:'ha', label:'ha', factor:100, digits:2},
      {value:'m2', label:'m²', factor:1000000, digits:0},
      {value:'acre', label:'acres', factor:247.105381, digits:2},
    ],
    distance:[
      {value:'km', label:'km', factor:1, digits:3},
      {value:'m', label:'m', factor:1000, digits:1},
      {value:'mi', label:'mi', factor:.621371192, digits:3},
      {value:'ft', label:'ft', factor:3280.839895, digits:1},
    ],
    elevation:[
      {value:'m', label:'m', factor:1, digits:1},
      {value:'ft', label:'ft', factor:3.280839895, digits:1},
    ],
  };
  function activeUnit(kind) {
    const definitions = unitDefinitions[kind] || [];
    return definitions.find(item => item.value === state.units[kind]) || definitions[0] || {value:'', label:'', factor:1, digits:2};
  }
  function converted(baseValue, kind) {
    const unit = activeUnit(kind);
    return {...unit, value:Number(baseValue) * unit.factor};
  }
  function unitOptions(kind) {
    return (unitDefinitions[kind] || []).map(unit => `<option value="${unit.value}" ${unit.value === activeUnit(kind).value ? 'selected' : ''}>${unit.label}</option>`).join('');
  }
  function metricWithUnit(label, baseValue, kind) {
    const value = converted(baseValue, kind);
    return `<div class="geo-metric"><div class="geo-metric-head"><span>${esc(label)}</span><select data-geo-unit="${kind}" aria-label="Unidad de ${esc(label)}">${unitOptions(kind)}</select></div><strong>${f2(value.value,value.digits)} ${value.label}</strong></div>`;
  }
  function clearElevationMarker() {
    if (state.elevationMarker && state.bridge?.map) state.bridge.map.removeLayer(state.elevationMarker);
    state.elevationMarker = null;
  }
  function inspectFeature(id) {
    const feature = state.bridge?.featureById(id);
    if (!feature) return;
    clearElevationMarker();
    state.selected = id;
    ui.inspector.style.display = '';
    ui.inspector.classList.add('open-mobile');
    const points = pointsFor(feature);
    const type = feature.geometry?.type || '';
    const isLine = type === 'LineString';
    const isPolygon = type === 'Polygon';
    const length = isLine ? state.bridge.lengthKm(points) : null;
    const perimeter = isPolygon ? closedPerimeter(points) : null;
    const area = isPolygon ? state.bridge.areaKm2(points) : null;
    const heading = isLine ? bearing(points) : null;
    ui.inspectorTitle.textContent = feature.name || geometryLabel(feature);
    ui.inspectorSubtitle.textContent = `${geometryLabel(feature)} · ${points.length} vértices`;
    ui.inspectorBody.innerHTML = `<div class="geo-metric-grid">
      ${isLine ? `${metricWithUnit('Longitud', length, 'distance')}<div class="geo-metric"><span>Rumbo inicial-final</span><strong>${f2(heading,1)}°</strong></div>` : ''}
      ${isPolygon ? `${metricWithUnit('Área', area, 'area')}${metricWithUnit('Perímetro', perimeter, 'distance')}` : ''}
      ${type === 'Point' ? `<div class="geo-metric"><span>Latitud</span><strong>${f2(points[0]?.[0],6)}</strong></div><div class="geo-metric"><span>Longitud</span><strong>${f2(points[0]?.[1],6)}</strong></div>` : ''}
    </div>
    <div class="geo-section"><h4>Perfil y estimación de elevación</h4><div id="geoElevationSlot" class="geo-elevation-card"><div class="geo-empty-state">Consultando elevación del terreno…</div></div></div>
    <div class="geo-section"><h4>Nombre y proyecto</h4><input id="geoFeatureName" value="${esc(feature.name || '')}" maxlength="120" style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:8px"></div>
    <div class="geo-inspector-actions">
      <button type="button" data-geo-action="analyze">Análisis completo</button>
      <button type="button" data-geo-action="ai">Analizar con IA</button>
      ${type !== 'Point' ? '<button type="button" data-geo-action="pdf">Informe PDF</button><button type="button" data-geo-action="docx">Informe Word</button>' : ''}
      <button type="button" data-geo-action="scene">Añadir a presentación</button>
      <button type="button" data-geo-action="zoom">Centrar en mapa</button>
    </div>`;
    const nameInput = el('geoFeatureName');
    nameInput?.addEventListener('change', () => {
      feature.name = nameInput.value.trim() || geometryLabel(feature);
      markDirty(true); state.bridge.refresh(); renderTree(); inspectFeature(feature.id);
    }, {once:true});
    loadElevation(feature);
    renderTree();
  }

  function samplePoints(points, max=100, min=60) {
    if (points.length <= 1) return points.slice();
    const distances = [0];
    for (let i=1; i<points.length; i++) distances.push(distances[i - 1] + state.bridge.lengthKm([points[i - 1], points[i]]));
    const total = distances[distances.length - 1];
    if (!Number.isFinite(total) || total <= 0) return points.slice(0, max);
    const count = Math.min(max, Math.max(min, points.length));
    const sampled = [];
    let segment = 1;
    for (let i=0; i<count; i++) {
      const target = total * i / Math.max(1, count - 1);
      while (segment < distances.length - 1 && distances[segment] < target) segment++;
      const startDistance = distances[segment - 1];
      const segmentDistance = Math.max(Number.EPSILON, distances[segment] - startDistance);
      const ratio = Math.max(0, Math.min(1, (target - startDistance) / segmentDistance));
      const start = points[segment - 1];
      const end = points[segment];
      sampled.push([start[0] + (end[0] - start[0]) * ratio, start[1] + (end[1] - start[1]) * ratio]);
    }
    return sampled;
  }
  async function loadElevation(feature) {
    const slot = el('geoElevationSlot');
    if (!slot) return;
    const original = pointsFor(feature);
    if (feature.geometry?.type === 'Polygon' && original.length > 2) original.push(original[0]);
    const points = samplePoints(original, 100, feature.geometry?.type === 'Point' ? 1 : 60);
    if (!points.length) return;
    const key = `${feature.id}|${points.map(point => point.join(',')).join(';')}`;
    if (state.elevation.has(key)) return renderElevation(slot, state.elevation.get(key));
    const token = ++state.elevationToken;
    try {
      const response = await fetch('api/elevation_profile.php', {
        method:'POST', headers:{'Content-Type':'application/json', ...(state.bridge.csrf ? {'X-CSRF-Token':state.bridge.csrf} : {})},
        body:JSON.stringify({coordinates:points}),
      });
      const data = await state.bridge.readJsonResponse(response);
      if (token !== state.elevationToken || state.selected !== feature.id) return;
      state.elevation.set(key, data);
      renderElevation(slot, data);
    } catch (error) {
      slot.innerHTML = `<div class="geo-empty-state">El perfil no está disponible ahora: ${esc(error.message)}. Las mediciones de longitud y área siguen siendo válidas.</div>`;
    }
  }
  function renderElevation(slot, data) {
    const profile = (data.profile || []).filter(item => Number.isFinite(Number(item.elevation_m)));
    const elevationUnit = activeUnit('elevation');
    const values = profile.map(item => Number(item.elevation_m) * elevationUnit.factor);
    if (!values.length) { slot.innerHTML = '<div class="geo-empty-state">Sin datos de elevación.</div>'; return; }
    const width=340, height=150, pad=22, min=Math.min(...values), max=Math.max(...values), span=Math.max(1,max-min);
    const path = values.map((value,index) => {
      const x = pad + index * (width-pad*2) / Math.max(1,values.length-1);
      const y = height-pad - (value-min) * (height-pad*2) / span;
      return `${index ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
    const fill = `${path} L${width-pad},${height-pad} L${pad},${height-pad} Z`;
    const minValue = converted(data.min_m, 'elevation');
    const avgValue = converted(data.avg_m, 'elevation');
    const maxValue = converted(data.max_m, 'elevation');
    slot.innerHTML = `<div class="geo-elevation-toolbar"><strong>Perfil del trayecto</strong><select data-geo-unit="elevation" aria-label="Unidad de elevación">${unitOptions('elevation')}</select></div>
      <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Perfil interactivo de elevación" data-geo-elevation-plot><path d="${fill}" fill="#bfe3fb" opacity=".75"></path><path d="${path}" fill="none" stroke="#0b6fae" stroke-width="2.5"></path><line data-elevation-cursor x1="${pad}" x2="${pad}" y1="${pad}" y2="${height-pad}" stroke="#f97316" stroke-width="1.5" stroke-dasharray="4 3"></line><circle data-elevation-point cx="${pad}" cy="${height-pad}" r="4.5" fill="#f97316" stroke="#fff" stroke-width="2"></circle><text x="5" y="18" font-size="10" fill="#52677d">${f2(max,1)} ${elevationUnit.label}</text><text x="5" y="${height-7}" font-size="10" fill="#52677d">${f2(min,1)} ${elevationUnit.label}</text><rect x="${pad}" y="${pad}" width="${width-pad*2}" height="${height-pad*2}" fill="transparent" class="geo-elevation-hit"></rect></svg>
      <div class="geo-elevation-slider"><input type="range" data-geo-elevation-slider min="0" max="${Math.max(0, profile.length - 1)}" value="0" step="1" aria-label="Recorrer el perfil de elevación" ${profile.length < 2 ? 'disabled' : ''}><div data-geo-elevation-readout></div></div>
      <div class="geo-elevation-meta"><span>Mín. ${f2(minValue.value,minValue.digits)} ${minValue.label}</span><span>Media ${f2(avgValue.value,avgValue.digits)} ${avgValue.label}</span><span>Máx. ${f2(maxValue.value,maxValue.digits)} ${maxValue.label}</span></div>
      <div class="geo-elevation-note">Estimación DEM de 90 m. Fuente: Copernicus DEM mediante Open-Meteo; no reemplaza levantamiento topográfico.</div>`;
    const slider = slot.querySelector('[data-geo-elevation-slider]');
    const plot = slot.querySelector('[data-geo-elevation-plot]');
    const update = index => updateElevationCursor(slot, profile, Math.max(0, Math.min(profile.length - 1, Number(index) || 0)), {width,height,pad,min,max});
    slider?.addEventListener('input', () => update(slider.value));
    plot?.addEventListener('pointermove', event => {
      const box = plot.getBoundingClientRect();
      const viewX = (event.clientX - box.left) / Math.max(1, box.width) * width;
      const ratio = Math.max(0, Math.min(1, (viewX - pad) / Math.max(1, width - pad * 2)));
      const index = Math.round(ratio * Math.max(0, profile.length - 1));
      if (slider) slider.value = String(index);
      update(index);
    });
    update(0);
  }
  function updateElevationCursor(slot, profile, index, chart) {
    const item = profile[index];
    if (!item) return;
    const value = converted(item.elevation_m, 'elevation');
    const distance = converted(item.distance_km, 'distance');
    const x = chart.pad + index * (chart.width-chart.pad*2) / Math.max(1, profile.length - 1);
    const convertedElevation = Number(item.elevation_m) * activeUnit('elevation').factor;
    const y = chart.height-chart.pad - (convertedElevation-chart.min) * (chart.height-chart.pad*2) / Math.max(1, chart.max-chart.min);
    const cursor = slot.querySelector('[data-elevation-cursor]');
    const point = slot.querySelector('[data-elevation-point]');
    cursor?.setAttribute('x1', x.toFixed(1)); cursor?.setAttribute('x2', x.toFixed(1));
    point?.setAttribute('cx', x.toFixed(1)); point?.setAttribute('cy', y.toFixed(1));
    const readout = slot.querySelector('[data-geo-elevation-readout]');
    if (readout) readout.innerHTML = `<strong>${f2(distance.value,distance.digits)} ${distance.label}</strong><span>${f2(value.value,value.digits)} ${value.label} · punto ${index + 1}/${profile.length}</span>`;
    const lat = Number(item.lat); const lng = Number(item.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || !state.bridge?.map || typeof L === 'undefined') return;
    const tooltip = `${f2(distance.value,distance.digits)} ${distance.label} · ${f2(value.value,value.digits)} ${value.label}`;
    if (!state.elevationMarker) {
      state.elevationMarker = L.circleMarker([lat,lng], {radius:7, color:'#fff', weight:2, fillColor:'#f97316', fillOpacity:1, pane:'markerPane'}).addTo(state.bridge.map).bindTooltip(tooltip, {direction:'top', offset:[0,-7]});
    } else {
      state.elevationMarker.setLatLng([lat,lng]);
      state.elevationMarker.setTooltipContent(tooltip);
    }
    state.elevationMarker.bringToFront?.();
    state.elevationMarker.openTooltip();
  }

  function addScene() {
    if (!state.bridge) return;
    const map = state.bridge.map;
    const feature = state.bridge.featureById(state.selected);
    state.scenes.push({
      id:`scene-${Date.now()}-${state.scenes.length}`,
      title:feature?.name || `Vista ${state.scenes.length + 1}`,
      center:{lat:map.getCenter().lat, lng:map.getCenter().lng}, zoom:map.getZoom(), selected:state.selected || '',
    });
    markDirty(true);
    notify(`Vista ${state.scenes.length} añadida a la presentación.`, 'ok');
  }
  function showScene(index) {
    if (!state.scenes.length) return;
    state.sceneIndex = (index + state.scenes.length) % state.scenes.length;
    const scene = state.scenes[state.sceneIndex];
    state.bridge.map.setView(scene.center, scene.zoom, {animate:true});
    if (scene.selected) state.bridge.select(scene.selected, false);
    ui.presentCounter.textContent = `${state.sceneIndex + 1} / ${state.scenes.length}`;
    ui.sceneTitle.value = scene.title || '';
  }
  function startPresentation() {
    if (!state.scenes.length) addScene();
    if (!state.scenes.length) return;
    document.body.classList.add('geo-presenting');
    ui.presentBar.hidden = false;
    showScene(0);
    state.bridge.map.invalidateSize({pan:false});
  }
  function stopPresentation() {
    document.body.classList.remove('geo-presenting');
    if (ui.presentBar) ui.presentBar.hidden = true;
    state.bridge?.map?.invalidateSize({pan:false});
  }

  async function loadProjectList() {
    if (!state.bridge || !ui.projectSelect) return;
    try {
      const response = await fetch('api/geo_projects.php', {cache:'no-store'});
      const data = await state.bridge.readJsonResponse(response);
      const selected = state.projectId || ui.projectSelect.value;
      ui.projectSelect.innerHTML = '<option value="">Proyectos guardados</option>' + (data.projects || []).map(project => `<option value="${esc(project.id)}" ${project.id === selected ? 'selected' : ''}>${esc(project.name)} · ${Number(project.feature_count || 0)} elem.</option>`).join('');
    } catch (error) {
      setStatus('No se pudo consultar la lista de proyectos guardados.');
    }
  }
  async function saveProject() {
    const name = ui.projectName.value.trim() || `Proyecto GEO ${new Date().toLocaleDateString('es-PE')}`;
    const snapshot = state.bridge.snapshot();
    if (!snapshot.features.length) return notify('Añada o importe al menos un elemento antes de guardar.', 'wa');
    ui.projectSave.disabled = true;
    try {
      const response = await fetch('api/geo_projects.php', {
        method:'POST', headers:{'Content-Type':'application/json', ...(state.bridge.csrf ? {'X-CSRF-Token':state.bridge.csrf} : {})},
        body:JSON.stringify({action:'save', id:state.projectId || null, name, project:{...snapshot, scenes:state.scenes, documents:state.projectDocuments}}),
      });
      const data = await state.bridge.readJsonResponse(response);
      state.projectId = data.project?.id || state.projectId;
      ui.projectName.value = data.project?.name || name;
      markDirty(false);
      await loadProjectList();
      notify('Proyecto GEO guardado. Los archivos temporales originales no fueron duplicados.', 'ok', 5200);
    } catch (error) {
      notify('No se pudo guardar el proyecto: ' + error.message, 'er', 6000);
    } finally { ui.projectSave.disabled = false; }
  }
  async function openProject() {
    const id = ui.projectSelect.value;
    if (!id) return notify('Seleccione un proyecto guardado.', 'wa');
    try {
      const response = await fetch(`api/geo_projects.php?id=${encodeURIComponent(id)}`, {cache:'no-store'});
      const data = await state.bridge.readJsonResponse(response);
      const project = data.project || {};
      state.projectId = project.id || id;
      ui.projectName.value = project.name || '';
      state.scenes = Array.isArray(project.data?.scenes) ? project.data.scenes : [];
      state.projectDocuments = Array.isArray(project.data?.documents) ? project.data.documents : [];
      state.bridge.importProject(project.data || {});
      state.selected = '';
      markDirty(false); renderTree();
      notify(`Proyecto “${project.name || id}” abierto.`, 'ok');
    } catch (error) { notify('No se pudo abrir el proyecto: ' + error.message, 'er', 6000); }
  }

  workspace.addEventListener('click', event => {
    const folderCheckbox = event.target.closest('[data-geo-folder]');
    if (folderCheckbox) event.stopPropagation();
    const draw = event.target.closest('[data-geo-draw]');
    if (draw) return startDraw(draw.dataset.geoDraw);
    const select = event.target.closest('[data-geo-select]');
    if (select) return state.bridge.select(select.dataset.geoSelect, false);
    const zoom = event.target.closest('[data-geo-zoom]');
    if (zoom) return state.bridge.select(zoom.dataset.geoZoom, true);
    const platform = event.target.closest('[data-platform-layer-label]');
    if (platform) return togglePlatformLayer(platform.dataset.platformLayerLabel);
    const action = event.target.closest('[data-geo-action]')?.dataset.geoAction;
    if (!action) return;
    const feature = state.bridge.featureById(state.selected);
    if (!feature) return;
    if (action === 'zoom') state.bridge.select(feature.id, true);
    if (action === 'scene') addScene();
    if (action === 'pdf' || action === 'docx') state.bridge.report(feature, action).catch(error => notify('No se pudo generar el informe: ' + error.message, 'er'));
    if (action === 'analyze') { close(); state.bridge.analyze(feature).catch(error => notify('No se pudo analizar: ' + error.message, 'er')); }
    if (action === 'ai') { close(); state.bridge.askAi(feature); }
  });
  workspace.addEventListener('change', event => {
    const unit = event.target.closest('[data-geo-unit]');
    if (unit) {
      const kind = unit.dataset.geoUnit;
      if (unitDefinitions[kind]?.some(item => item.value === unit.value)) state.units[kind] = unit.value;
      if (state.selected) inspectFeature(state.selected);
      return;
    }
    const folder = event.target.closest('[data-geo-folder]');
    if (folder) {
      const ids = state.folderIndex.get(folder.dataset.geoFolder) || [];
      state.bridge.setVisibility(ids, folder.checked);
      markDirty(true); renderTree();
      return;
    }
    const visible = event.target.closest('[data-geo-visible]');
    if (visible) {
      const feature = state.bridge.featureById(visible.dataset.geoVisible);
      if (feature) { state.bridge.setFeatureMeta(feature, {visible:visible.checked}); markDirty(true); renderTree(); }
      return;
    }
    const platform = event.target.closest('[data-platform-layer]');
    if (platform) togglePlatformLayer(platform.dataset.platformLayer);
  });
  workspace.addEventListener('toggle', event => {
    const details = event.target.closest?.('[data-geo-node]');
    if (!details || !ui.tree?.contains(details)) return;
    const key = details.dataset.geoNode;
    state.folderTouched.add(key);
    if (details.open) state.folderOpen.add(key); else state.folderOpen.delete(key);
  }, true);

  ui.close?.addEventListener('click', close);
  ui.file?.addEventListener('change', () => loadGeoFile(ui.file.files?.[0]));
  ui.treeRefresh?.addEventListener('click', renderTree);
  ui.allOn?.addEventListener('click', () => {
    state.bridge.setAllVisible(true); markDirty(true); renderTree();
  });
  ui.allOff?.addEventListener('click', () => {
    state.bridge.setAllVisible(false); markDirty(true); renderTree();
  });
  ui.inspectorClose?.addEventListener('click', () => { clearElevationMarker(); ui.inspector.style.display = 'none'; ui.inspector.classList.remove('open-mobile'); });
  ui.drawFinish?.addEventListener('click', finishDraw);
  ui.drawUndo?.addEventListener('click', () => { state.drawing.points.pop(); updateDraft(); });
  ui.addScene?.addEventListener('click', addScene);
  ui.presentStart?.addEventListener('click', startPresentation);
  ui.presentStop?.addEventListener('click', stopPresentation);
  ui.presentPrev?.addEventListener('click', () => showScene(state.sceneIndex - 1));
  ui.presentNext?.addEventListener('click', () => showScene(state.sceneIndex + 1));
  ui.sceneTitle?.addEventListener('change', () => {
    if (!state.scenes[state.sceneIndex]) return;
    state.scenes[state.sceneIndex].title = ui.sceneTitle.value.trim() || `Vista ${state.sceneIndex + 1}`;
    markDirty(true);
  });
  ui.projectSave?.addEventListener('click', saveProject);
  ui.projectLoad?.addEventListener('click', openProject);
  window.addEventListener('keydown', event => {
    if (!state.open) return;
    if (event.key === 'Escape') {
      if (document.body.classList.contains('geo-presenting')) stopPresentation();
      else if (state.drawing.mode) cancelDraw();
      else close();
    }
    if (event.key === 'Enter' && state.drawing.mode && event.target.tagName !== 'INPUT') finishDraw();
  });
})();
