/**
 * app.js — SIG Energético-Minero OSINERGMIN
 * Three.js Earth 3D + Login cinemático + Leaflet mapa + Análisis de carga
 */

/* ====================================================================
   CONSTANTES ANÁLISIS
==================================================================== */
const MESES   = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const MESES_C = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const FACTORES = [0.85,0.82,0.78,0.72,0.68,0.75,0.82,0.88,0.80,0.75,0.78,0.83];

const SIG = { analizadas:0, dispTotal:0, activeMkr:null, geoLayers:{}, featureIndex:[] };
const APP_CSRF = (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN)
  || document.querySelector('meta[name="csrf-token"]')?.content
  || '';
function csrfHeaders(extra={}) {
  return APP_CSRF ? {...extra, 'X-CSRF-Token': APP_CSRF} : extra;
}

/* ====================================================================
   TOAST
==================================================================== */
function toast(msg, type='in', ms=3500) {
  const c = document.getElementById('toasts');
  if (!c) return;
  const icons = { ok:'\u2713', er:'\u2715', in:'\u2139', wa:'\u26A0' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${icons[type]||'i'}</span><span>${msg}</span>`;
  c.appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 280); }, ms);
}

/* ====================================================================
   THREE.JS — GLOBO TERRÁQUEO 3D
   Siempre activo como fondo, visible detrás
   del login y del mapa
==================================================================== */
const _Earth = (function () {
  const canvas = document.getElementById('earth-canvas');
  if (!canvas || typeof THREE === 'undefined') return { doZoom:false };

  const W = window.innerWidth, H = window.innerHeight;
  const renderer = new THREE.WebGLRenderer({ canvas, antialias:true });
  renderer.setSize(W, H);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setClearColor(0x010a18, 1);

  const scene  = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(42, W/H, 0.1, 2000);
  camera.position.z = 3.0;
  const globe = new THREE.Group();
  scene.add(globe);
  function placeGlobe() {
    globe.position.x = window.innerWidth < 768 ? 0 : .86;
    globe.position.y = window.innerWidth < 768 ? .46 : 0;
  }
  placeGlobe();

  /* Estrellas */
  const sGeo = new THREE.BufferGeometry();
  const sArr = new Float32Array(18000 * 3);
  for (let i = 0; i < sArr.length; i++) sArr[i] = (Math.random() - .5) * 1600;
  sGeo.setAttribute('position', new THREE.BufferAttribute(sArr, 3));
  scene.add(new THREE.Points(sGeo,
    new THREE.PointsMaterial({ color:0xffffff, size:.55, sizeAttenuation:true, transparent:true, opacity:.88 })));

  /* Tierra */
  const loader  = new THREE.TextureLoader();
  const earthGeo = new THREE.SphereGeometry(1, 64, 64);
  const earthMat = new THREE.MeshPhongMaterial({
    color:0x1a6fa8, specular:new THREE.Color(0x0a1a2a),
    shininess:14, emissive:new THREE.Color(0x001525), emissiveIntensity:.22,
  });
  const earth = new THREE.Mesh(earthGeo, earthMat);
  globe.add(earth);

  /* Cargar textura — múltiples fuentes de respaldo */
  const texUrls = [
    'https://unpkg.com/three-globe@2.30.0/example/img/earth-blue-marble.jpg',
    'https://raw.githubusercontent.com/mrdoob/three.js/master/examples/textures/planets/earth_atmos_2048.jpg',
    'https://threejs.org/examples/textures/planets/earth_atmos_2048.jpg',
  ];
  (function tryLoad(i) {
    if (i >= texUrls.length) return;
    loader.load(texUrls[i],
      tex => { earthMat.map = tex; earthMat.needsUpdate = true; },
      undefined,
      () => tryLoad(i + 1)
    );
  })(0);

  /* Halo atmosférico */
  const haloMat = new THREE.ShaderMaterial({
    vertexShader:`
      varying vec3 vN;
      void main(){ vN=normalize(normalMatrix*normal);
        gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }`,
    fragmentShader:`
      varying vec3 vN;
      void main(){
        float i=pow(.58-dot(vN,vec3(0.,0.,1.)),.9);
        gl_FragColor=vec4(0.,0.45,1.,1.)*i*.65; }`,
    side:THREE.FrontSide, blending:THREE.AdditiveBlending,
    transparent:true, depthWrite:false,
  });
  const halo = new THREE.Mesh(new THREE.SphereGeometry(1.08,64,64), haloMat);
  globe.add(halo);

  /* Atmósfera interior */
  const atmosphere = new THREE.Mesh(
    new THREE.SphereGeometry(1.013,64,64),
    new THREE.MeshPhongMaterial({ color:0x0055cc, transparent:true, opacity:.06, side:THREE.BackSide })
  );
  globe.add(atmosphere);

  /* Nubes */
  const cloudMat = new THREE.MeshPhongMaterial({ transparent:true, opacity:0, depthWrite:false });
  const clouds   = new THREE.Mesh(new THREE.SphereGeometry(1.011,64,64), cloudMat);
  globe.add(clouds);
  loader.load('https://threejs.org/examples/textures/planets/earth_clouds_1024.png',
    t => { cloudMat.map = t; cloudMat.opacity = .3; cloudMat.needsUpdate = true; });

  /* Iluminación */
  scene.add(new THREE.AmbientLight(0x223355, .42));
  const sun = new THREE.DirectionalLight(0xffeedd, 1.3);
  sun.position.set(5, 3, 5);
  scene.add(sun);
  const fill = new THREE.DirectionalLight(0x112244, .3);
  fill.position.set(-5, -2, -3);
  scene.add(fill);

  /* Estado de zoom */
  const state = { doZoom:false, zoomSpeed:.010, onZoomEnd:null };

  /* Loop de render */
  (function loop() {
    requestAnimationFrame(loop);
    earth.rotation.y  += .0016;
    clouds.rotation.y += .0020;
    if (state.doZoom) {
      camera.position.z -= state.zoomSpeed;
      state.zoomSpeed   += .007;
      globe.position.x  *= .94;
      globe.position.y  *= .94;
      if (camera.position.z <= .2) {
        state.doZoom = false;
        state.onZoomEnd && state.onZoomEnd();
      }
    }
    renderer.render(scene, camera);
  })();

  /* Resize */
  window.addEventListener('resize', () => {
    const w = window.innerWidth, h = window.innerHeight;
    camera.aspect = w/h; camera.updateProjectionMatrix();
    renderer.setSize(w, h);
    placeGlobe();
  });

  return state;
})();

/* ====================================================================
   HELPERS ANIMACIÓN
==================================================================== */
const wait = ms => new Promise(r => setTimeout(r, ms));

function applySessionRole(role, name) {
  const nextRole = role === 'admin' ? 'admin' : 'viewer';
  document.body.dataset.role = nextRole;
  document.querySelectorAll('.usr-name').forEach(el => { el.textContent = name || 'usuario'; });
  document.querySelectorAll('.usr-role').forEach(el => {
    el.textContent = nextRole === 'admin' ? 'Administrador' : 'Visualizador';
  });
}

async function cinShow(el, dur=700) {
  el.style.display = 'flex';
  el.style.animation = `fadeInScale ${dur}ms cubic-bezier(.22,1,.36,1) both`;
  await wait(dur);
}
async function cinHide(el, dur=600) {
  el.style.animation = `fadeOutScale ${dur}ms ease forwards`;
  await wait(dur);
  el.style.display = 'none';
}

/* ====================================================================
   SECUENCIA CINEMÁTICA POST-LOGIN
==================================================================== */
async function runCinematic(role) {
  const loginScr  = document.getElementById('login-screen');
  const cinWel    = document.getElementById('cin-welcome');
  const cinLogo   = document.getElementById('cin-logo');
  const cinFlash  = document.getElementById('cin-flash');
  const cinTitle  = document.getElementById('cinTitle');
  const app       = document.getElementById('app');

  /* 1 — Slide-up del login */
  loginScr.style.animation = 'loginOut .85s cubic-bezier(.4,0,.6,1) forwards';
  await wait(880);
  loginScr.style.display = 'none';

  /* 2 — Mensaje de bienvenida */
  cinTitle.textContent = role === 'admin' ? 'Bienvenido Administrador' : 'Bienvenido Usuario';
  await cinShow(cinWel, 700);
  await wait(2300);
  await cinHide(cinWel, 600);
  await wait(150);

  /* 3 — Logo CVC Energía */
  await cinShow(cinLogo, 700);
  await wait(2100);
  await cinHide(cinLogo, 600);
  await wait(150);

  /* 4 — Zoom cinematográfico al planeta */
  await new Promise(resolve => {
    if (_Earth && _Earth.doZoom !== undefined) {
      _Earth.zoomSpeed = .010;
      _Earth.doZoom    = true;
      _Earth.onZoomEnd = resolve;
    } else {
      resolve();
    }
  });

  /* 5 — Flash de transición */
  cinFlash.style.display    = 'block';
  cinFlash.style.transition = 'opacity .5s ease';
  cinFlash.style.opacity    = '0';
  await wait(40);
  cinFlash.style.opacity = '1';
  await wait(500);

  /* 6 — Mostrar el sistema principal */
  app.classList.add('visible');
  cinFlash.style.opacity = '0';
  await wait(500);
  cinFlash.style.display = 'none';

  /* Inicializar Leaflet después de que el mapa es visible */
  initMap();
}

/* ====================================================================
   FORMULARIO DE LOGIN
==================================================================== */
const loginForm = document.getElementById('loginForm');
if (loginForm) {
  async function completeLogin(data, labelEl, spinEl, buttonEl, fallbackName) {
    applySessionRole(data.role, data.name || fallbackName);
    if (spinEl) spinEl.style.display = 'none';
    if (labelEl) labelEl.innerHTML = '&#9989; &nbsp;Acceso concedido';
    if (buttonEl) {
      buttonEl.style.background = 'linear-gradient(135deg,#064e3b,#059669)';
      buttonEl.style.boxShadow  = '0 8px 24px rgba(16,185,129,.4)';
    }
    await wait(380);
    await runCinematic(data.role);
  }

  loginForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn  = document.getElementById('loginBtn');
    const spin = document.getElementById('loginSpin');
    const txt  = document.getElementById('loginTxt');
    const err  = document.getElementById('loginErr');

    btn.disabled = true;
    spin.style.display = 'block';
    txt.textContent = 'Verificando acceso…';
    err.style.display = 'none';

    try {
      const fd   = new FormData(this);
      const resp = await fetch('api/auth.php', {
        method: 'POST',
        headers: csrfHeaders(),
        body: fd,
      });
      const data = await resp.json();

      if (data.ok) {
        await completeLogin(data, txt, spin, btn, fd.get('usuario'));
      } else {
        btn.disabled = false;
        spin.style.display = 'none';
        txt.innerHTML = '&#128273; &nbsp;Ingresar al sistema';
        btn.style.background = '';
        btn.style.boxShadow  = '';
        err.style.display = 'block';
        err.textContent   = '\u26A0 ' + (data.msg || 'Credenciales incorrectas');
        const card = document.getElementById('login-card');
        card.style.animation = 'none';
        void card.offsetWidth;
        card.style.animation = 'shake .4s ease';
      }
    } catch (ex) {
      btn.disabled = false;
      spin.style.display = 'none';
      txt.innerHTML = '&#128273; &nbsp;Ingresar al sistema';
      err.style.display = 'block';
      err.textContent   = 'Error de conexión. Verifique su conexión e intente de nuevo.';
    }
  });

  /* Toggle contraseña */
  const guestBtn = document.getElementById('guestLoginBtn');
  if (guestBtn) {
    guestBtn.addEventListener('click', async function () {
      const err = document.getElementById('loginErr');
      const txt = document.getElementById('guestLoginTxt');
      const loginBtn = document.getElementById('loginBtn');
      this.disabled = true;
      if (loginBtn) loginBtn.disabled = true;
      if (err) err.style.display = 'none';
      if (txt) txt.textContent = 'Ingresando...';

      try {
        const fd = new FormData();
        fd.append('guest', '1');
        const token = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_csrf"]')?.value || '';
        if (token) fd.append('_csrf', token);
        const resp = await fetch('api/auth.php', {
          method: 'POST',
          headers: csrfHeaders(),
          body: fd,
        });
        const data = await resp.json();
        if (data.ok) {
          await completeLogin(data, txt, null, this, 'Invitado');
        } else {
          this.disabled = false;
          if (loginBtn) loginBtn.disabled = false;
          if (txt) txt.innerHTML = '&#128065;&#65039; &nbsp;Ingresar como invitado';
          if (err) {
            err.style.display = 'block';
            err.textContent = '\u26A0 ' + (data.msg || 'No se pudo iniciar como invitado');
          }
        }
      } catch (ex) {
        this.disabled = false;
        if (loginBtn) loginBtn.disabled = false;
        if (txt) txt.innerHTML = '&#128065;&#65039; &nbsp;Ingresar como invitado';
        if (err) {
          err.style.display = 'block';
          err.textContent = 'Error de conexión. Verifique su conexión e intente de nuevo.';
        }
      }
    });
  }

  document.getElementById('tpwBtn').addEventListener('click', function () {
    const i = document.getElementById('pw');
    i.type  = i.type === 'password' ? 'text' : 'password';
    const label = i.type === 'password' ? 'Ver contraseña' : 'Ocultar contraseña';
    this.innerHTML = '&#128065;&#65039;';
    this.setAttribute('aria-label', label);
    this.setAttribute('title', label);
  });
}

/* ====================================================================
   ANÁLISIS DE DISPONIBILIDAD (determinista)
==================================================================== */
function seededRand(seed) {
  let s = seed >>> 0;
  return () => { s = (Math.imul(s,1664525)+1013904223)>>>0; return s/0xFFFFFFFF; };
}
function strSeed(str) {
  let h = 5381;
  for (let i=0; i<str.length; i++) h = ((h<<5)+h^str.charCodeAt(i))>>>0;
  return h;
}
function analizarSE(se) {
  const rand  = seededRand(strSeed(se.nombre));
  const filas = FACTORES.map((b,i) => {
    const v  = 0.90 + rand()*0.20;
    const kW = b*v*se.potencia*se.fp*1000;
    return { mes:MESES[i], mesC:MESES_C[i],
             energia:Math.round(kW*1000/4), kW, mva:kW/(se.fp*1000) };
  });
  const maxMVA  = Math.max(...filas.map(f=>f.mva));
  const disp    = se.potencia - maxMVA;
  const peakIdx = filas.findIndex(f=>f.mva===maxMVA);
  return { filas, maxMVA, disp, peakIdx, ut:(maxMVA/se.potencia)*100 };
}

const f2 = (n,d=2) => n !== null && n !== undefined && n !== '' && Number.isFinite(Number(n))
  ? Number(n).toLocaleString('es-PE',{minimumFractionDigits:d,maximumFractionDigits:d})
  : '—';
const fi = n => Math.round(n).toLocaleString('es-PE');

/* ====================================================================
   LEAFLET — MAPA
   initMap() es llamado DESPUÉS de que
   el contenedor #map es visible
==================================================================== */
let mapInstance = null;

function initMap() {
  if (mapInstance) return; // ya inicializado

  /* Forzar dimensiones explícitas al contenedor */
  const mapWrap = document.getElementById('map-wrap');
  const mapDiv  = document.getElementById('map');
  if (!mapDiv) return;

  mapInstance = L.map('map', {
    zoomControl: true, minZoom:3, maxZoom:19, preferCanvas:true,
  }).setView([-11.0, -75.5], 6);

  /* ?"??"? Capas base ?"??"? */
  const BASES = [
    /* 0 */ L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
              { attribution:'© OpenStreetMap', maxZoom:19, maxNativeZoom:19 }),
    /* 1 */ L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
              { attribution:'© Esri, Maxar', maxZoom:19, maxNativeZoom:19 }),
    /* 2 */ L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
              { attribution:'© Esri, HERE, OSM', maxZoom:19, maxNativeZoom:19 }),
    /* 3 */ L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
              { attribution:'© CARTO, © OSM', subdomains:'abcd', maxZoom:19, maxNativeZoom:19 }),
  ];
  const BASE_NAMES = ['Calles (OSM)','Satélite','Topográfico','Oscuro (CARTO)'];
  let baseIdx = 0;
  BASES[baseIdx].addTo(mapInstance);

  /* Si CartoDB falla, cambiar a OSM automáticamente */
  BASES[3].on('tileerror', () => {
    if (baseIdx === 3) {
      BASES[3].remove(); baseIdx = 0;
      BASES[0].addTo(mapInstance);
      document.getElementById('btnBase').textContent = BASE_NAMES[0];
    }
  });

  /* ?"??"? Controles header ?"??"? */
  document.getElementById('btnBase').addEventListener('click', () => {
    BASES[baseIdx].remove();
    baseIdx = (baseIdx+1) % BASES.length;
    BASES[baseIdx].addTo(mapInstance);
    BASES[baseIdx].bringToBack();
    document.getElementById('btnBase').textContent = BASE_NAMES[baseIdx];
    toast('Mapa base: '+BASE_NAMES[baseIdx], 'in', 2000);
  });
  document.getElementById('btnHome').addEventListener('click', () =>
    mapInstance.flyTo([-11.0,-75.5], 6, {duration:1.2}));
  document.getElementById('btnFs').addEventListener('click', () => {
    if (!document.fullscreenElement) document.documentElement.requestFullscreen();
    else document.exitFullscreen();
  });
  document.getElementById('menuBtn').addEventListener('click', () =>
    document.getElementById('sb').classList.toggle('open'));
  document.getElementById('dockLayers')?.addEventListener('click', () => {
    const sb = document.getElementById('sb');
    const body = document.getElementById('body');
    if (window.matchMedia('(max-width:768px)').matches) sb.classList.toggle('open');
    else sb.classList.toggle('collapsed');
    body?.classList.toggle('sb-collapsed', sb.classList.contains('collapsed'));
    setTimeout(() => mapInstance.invalidateSize(), 260);
  });
  document.getElementById('dockLegend')?.addEventListener('click', () => {
    const legend = document.getElementById('legend');
    if (legend) legend.classList.toggle('hidden');
  });
  (function enableFloatingSidebar() {
    const sb = document.getElementById('sb');
    const bar = document.getElementById('sb-dragbar');
    const close = document.getElementById('sbClose');
    if (!sb || !bar) return;
    close?.addEventListener('click', e => {
      e.stopPropagation();
      sb.classList.add('collapsed');
      document.getElementById('body')?.classList.add('sb-collapsed');
    });
    let drag = null;
    bar.addEventListener('mousedown', e => {
      if (e.target.closest('button')) return;
      const r = sb.getBoundingClientRect();
      const pr = (sb.offsetParent || document.body).getBoundingClientRect();
      drag = { dx:e.clientX - r.left, dy:e.clientY - r.top, w:r.width, h:r.height, parentLeft:pr.left, parentTop:pr.top, parentW:pr.width, parentH:pr.height };
      sb.style.height = `${Math.min(r.height, pr.height - 16)}px`;
      sb.style.maxHeight = `${Math.max(260, pr.height - 16)}px`;
      sb.classList.add('dragging');
      e.preventDefault();
    });
    window.addEventListener('mousemove', e => {
      if (!drag) return;
      const maxX = drag.parentW - drag.w - 8;
      const maxY = drag.parentH - drag.h - 8;
      const x = Math.max(8, Math.min(maxX, e.clientX - drag.parentLeft - drag.dx));
      const y = Math.max(8, Math.min(maxY, e.clientY - drag.parentTop - drag.dy));
      sb.style.left = `${x}px`;
      sb.style.top = `${y}px`;
      sb.style.right = 'auto';
      sb.style.bottom = 'auto';
    });
    window.addEventListener('mouseup', () => {
      if (!drag) return;
      drag = null;
      sb.classList.remove('dragging');
    });
  })();

  const zoneState = {
    active:false,
    drawing:false,
    points:[],
    draft:null,
    polygon:null,
    layer:L.layerGroup().addTo(mapInstance),
    opportunities:null,
    opportunityData:[],
    last:null,
    seq:0,
    suggestSeq:0,
  };
  const exportState = {
    seq:0,
    items:new Map(),
    selected:new Set(),
    dock:null,
  };
  const tempGeoState = {
    layer:L.layerGroup().addTo(mapInstance),
    features:[],
    layerById:new Map(),
    metaById:new Map(),
    analysisById:new Map(),
    pendingById:new Map(),
    selected:null,
    seq:0,
    notice:'',
  };

  const analysisPanel = document.getElementById('analysisPanel');
  const analysisPanelTitle = document.getElementById('analysisPanelTitle');
  const analysisPanelSub = document.getElementById('analysisPanelSub');
  const analysisPanelBody = document.getElementById('analysisPanelBody');
  const setAnalysisResults = new Map();
  const lineAnalysisResults = new Map();
  function openAnalysisPanel(title, html, sub='Disponibilidad, carga, PIT y PT COES') {
    if (!analysisPanel || !analysisPanelBody) return;
    if (analysisPanelTitle) analysisPanelTitle.textContent = title || 'Análisis técnico';
    if (analysisPanelSub) analysisPanelSub.textContent = sub;
    analysisPanelBody.innerHTML = html || '';
    analysisPanel.classList.add('open');
  }
  document.getElementById('analysisPanelClose')?.addEventListener('click', () => {
    analysisPanel?.classList.remove('open');
  });

  function zoneStatusClass(score) {
    const n = Number(score);
    if (n >= 70) return 'st-ok';
    if (n >= 45) return 'st-warn';
    return 'st-no';
  }
  function setZoneDrawMode(active) {
    zoneState.active = !!active;
    zoneState.drawing = false;
    document.getElementById('dockDraw')?.classList.toggle('on', zoneState.active);
    mapDiv.classList.toggle('draw-mode', zoneState.active);
    if (zoneState.active) {
      mapInstance.dragging.disable();
      mapInstance.doubleClickZoom.disable();
      toast('Dibuja la zona manteniendo presionado el mouse. Suelta para analizar.', 'in', 4500);
    } else {
      clearZoneDraft();
      mapInstance.dragging.enable();
      mapInstance.doubleClickZoom.enable();
    }
  }
  function clearZoneDraft() {
    if (zoneState.draft) zoneState.layer.removeLayer(zoneState.draft);
    zoneState.draft = null;
    zoneState.drawing = false;
  }
  function clearZoneAll() {
    clearZoneDraft();
    if (zoneState.polygon) zoneState.layer.removeLayer(zoneState.polygon);
    if (zoneState.opportunities) zoneState.layer.removeLayer(zoneState.opportunities);
    zoneState.polygon = null;
    zoneState.opportunities = null;
    zoneState.points = [];
    zoneState.last = null;
    setZoneDrawMode(false);
    mapInstance.closePopup();
    toast('Zona limpia', 'ok', 1800);
  }
  function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }
  function cleanServerMessage(text) {
    return String(text || '')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/(p|div|li|h[1-6])>/gi, '\n')
      .replace(/<[^>]+>/g, ' ')
      .replace(/&nbsp;/gi, ' ')
      .replace(/&quot;/gi, '"')
      .replace(/&#039;|&apos;/gi, "'")
      .replace(/&lt;/gi, '<')
      .replace(/&gt;/gi, '>')
      .replace(/&amp;/gi, '&')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 900);
  }
  async function readJsonResponse(resp) {
    const text = await resp.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch (err) {
      const msg = cleanServerMessage(text) || `Respuesta no JSON del servidor (HTTP ${resp.status})`;
      throw new Error(msg);
    }
    if (!resp.ok) {
      throw new Error(data.message || data.msg || data.error || `HTTP ${resp.status}`);
    }
    return data;
  }
  async function downloadSetReport(reportId, format='pdf', btn=null) {
    const analysis = setAnalysisResults.get(reportId);
    if (!analysis) {
      toast('Primero ejecuta el análisis de disponibilidad.', 'wa', 3500);
      return;
    }
    const old = btn?.innerHTML;
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="small-spinner"></span> Generando...';
    }
    try {
      const resp = await fetch('api/set_report.php', {
        method:'POST',
        headers:csrfHeaders({'Content-Type':'application/json'}),
        body:JSON.stringify({format, analysis}),
      });
      if (!resp.ok) throw new Error(await resp.text());
      const blob = await resp.blob();
      const cd = resp.headers.get('Content-Disposition') || '';
      const m = cd.match(/filename="?([^"]+)"?/i);
      const ext = format === 'xlsx' ? 'xlsx' : (format === 'docx' ? 'docx' : 'pdf');
      saveBlob(blob, m?.[1] || `disponibilidad_set_${new Date().toISOString().slice(0,10)}.${ext}`);
      toast(`Reporte ${ext.toUpperCase()} generado`, 'ok', 2800);
    } catch (err) {
      toast('No se pudo generar el reporte: ' + err.message, 'er', 6000);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = old || (format === 'xlsx' ? 'Excel' : (format === 'docx' ? 'Word' : 'PDF'));
      }
    }
  }
  async function downloadLineReport(reportId, format='pdf', btn=null) {
    const analysis = lineAnalysisResults.get(reportId);
    if (!analysis) {
      toast('Primero ejecuta la carga de línea.', 'wa', 3500);
      return;
    }
    const old = btn?.innerHTML;
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="small-spinner"></span> Generando...';
    }
    try {
      const resp = await fetch('api/line_report.php', {
        method:'POST',
        headers:csrfHeaders({'Content-Type':'application/json'}),
        body:JSON.stringify({format, analysis}),
      });
      if (!resp.ok) throw new Error(await resp.text());
      const blob = await resp.blob();
      const cd = resp.headers.get('Content-Disposition') || '';
      const m = cd.match(/filename="?([^"]+)"?/i);
      const ext = format === 'docx' ? 'docx' : 'pdf';
      saveBlob(blob, m?.[1] || `carga_linea_${new Date().toISOString().slice(0,10)}.${ext}`);
      toast(`Reporte ${ext.toUpperCase()} de línea generado`, 'ok', 2800);
    } catch (err) {
      toast('No se pudo generar el reporte de línea: ' + err.message, 'er', 6000);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = old || (format === 'docx' ? 'Exportar Word' : 'Exportar PDF');
      }
    }
  }
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-set-report], [data-line-report]');
    if (!btn) return;
    e.preventDefault();
    if (btn.dataset.setReport) {
      downloadSetReport(btn.dataset.setReport, btn.dataset.format || 'pdf', btn);
    } else {
      downloadLineReport(btn.dataset.lineReport, btn.dataset.format || 'pdf', btn);
    }
  });
  function xmlEsc(v) {
    return String(v ?? '').replace(/[&<>"']/g, ch => ({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&apos;',
    }[ch]));
  }
  function geometryKml(geom) {
    if (!geom || !geom.type || !geom.coordinates) return '';
    const coords = line => (line || []).map(p => `${Number(p[0]).toFixed(7)},${Number(p[1]).toFixed(7)},0`).join(' ');
    if (geom.type === 'Point') return `<Point><coordinates>${coords([geom.coordinates])}</coordinates></Point>`;
    if (geom.type === 'LineString') return `<LineString><coordinates>${coords(geom.coordinates)}</coordinates></LineString>`;
    if (geom.type === 'MultiLineString') return `<MultiGeometry>${geom.coordinates.map(line => `<LineString><coordinates>${coords(line)}</coordinates></LineString>`).join('')}</MultiGeometry>`;
    if (geom.type === 'Polygon') return `<Polygon><outerBoundaryIs><LinearRing><coordinates>${coords(geom.coordinates[0])}</coordinates></LinearRing></outerBoundaryIs></Polygon>`;
    if (geom.type === 'MultiPolygon') {
      return `<MultiGeometry>${geom.coordinates.map(poly => `<Polygon><outerBoundaryIs><LinearRing><coordinates>${coords(poly[0])}</coordinates></LinearRing></outerBoundaryIs></Polygon>`).join('')}</MultiGeometry>`;
    }
    return '';
  }
  function featureTitleForExport(feature, fallback='Elemento') {
    return searchTitle(feature?.properties || {}, fallback);
  }
  function featureToKmlPlacemark(feature, name='Elemento') {
    const geom = geometryKml(feature?.geometry);
    if (!geom) return '';
    const props = feature?.properties || {};
    const desc = Object.entries(props)
      .filter(([k, v]) => v !== null && v !== undefined && typeof v !== 'object' && String(v).trim() !== '')
      .slice(0, 40)
      .map(([k, v]) => `<tr><td>${xmlEsc(prettyLabel(k))}</td><td>${xmlEsc(formatAttrValue(k, v))}</td></tr>`)
      .join('');
    return `<Placemark><name>${xmlEsc(name)}</name><description><![CDATA[<table>${desc}</table>]]></description>${geom}</Placemark>`;
  }
  function exportFeaturesToKml(features, filename='seleccion_geovisor.kml') {
    const placemarks = features
      .map((feature, idx) => featureToKmlPlacemark(feature, featureTitleForExport(feature, `Elemento ${idx + 1}`)))
      .filter(Boolean)
      .join('');
    if (!placemarks) return toast('La entidad no tiene geometría exportable.', 'wa', 3000);
    const kml = `<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>${xmlEsc(filename.replace(/\.kml$/i, ''))}</name>${placemarks}</Document></kml>`;
    saveBlob(new Blob([kml], {type:'application/vnd.google-earth.kml+xml'}), filename);
  }
  function ensureExportDock() {
    if (exportState.dock) return exportState.dock;
    const dock = document.createElement('div');
    dock.id = 'exportDock';
    dock.className = 'export-dock hidden';
    dock.innerHTML = '<span data-export-count>0 seleccionados</span><button type="button" data-export-selected-kml>KML</button><button type="button" data-export-clear>Limpiar</button>';
    document.body.appendChild(dock);
    exportState.dock = dock;
    return dock;
  }
  function refreshExportDock() {
    const dock = ensureExportDock();
    const count = exportState.selected.size;
    dock.classList.toggle('hidden', count === 0);
    dock.querySelector('[data-export-count]').textContent = `${count} seleccionado${count === 1 ? '' : 's'}`;
  }
  function registerExportFeature(feature, title='Elemento', source='') {
    const type = feature?.geometry?.type || '';
    if (!/Point|LineString|Polygon/i.test(type)) return '';
    const id = `xf-${++exportState.seq}`;
    exportState.items.set(id, {feature, title, source});
    return id;
  }
  document.addEventListener('click', e => {
    const one = e.target?.closest?.('[data-export-feature]');
    const add = e.target?.closest?.('[data-export-add]');
    const all = e.target?.closest?.('[data-export-selected-kml]');
    const clear = e.target?.closest?.('[data-export-clear]');
    if (!one && !add && !all && !clear) return;
    e.preventDefault();
    e.stopPropagation();
    if (one) {
      const item = exportState.items.get(one.dataset.exportFeature);
      if (item) exportFeaturesToKml([item.feature], `${String(item.title || 'elemento').replace(/[^a-z0-9_-]+/gi, '_').slice(0, 60) || 'elemento'}.kml`);
      return;
    }
    if (add) {
      const id = add.dataset.exportAdd;
      if (exportState.items.has(id)) {
        exportState.selected.add(id);
        refreshExportDock();
        toast('Entidad agregada a la seleccion KML', 'ok', 1800);
      }
      return;
    }
    if (all) {
      const features = [...exportState.selected].map(id => exportState.items.get(id)?.feature).filter(Boolean);
      exportFeaturesToKml(features, `seleccion_geovisor_${new Date().toISOString().slice(0,10)}.kml`);
      return;
    }
    if (clear) {
      exportState.selected.clear();
      refreshExportDock();
    }
  });
  document.addEventListener('click', e => {
    const btn = e.target?.closest?.('[data-cira-zoom]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const lat = Number(btn.dataset.lat);
    const lng = Number(btn.dataset.lng);
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      mapInstance.flyTo([lat, lng], Math.max(8, mapInstance.getZoom() + 2), {duration:.8});
      mapInstance.closePopup();
    }
  });
  document.addEventListener('click', e => {
    const btn = e.target?.closest?.('[data-cira-detail]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const id = btn.dataset.ciraDetail || '';
    if (id) loadCiraDetail(id);
  });
  document.addEventListener('click', e => {
    const btn = e.target?.closest?.('[data-cira-pdf]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const id = btn.dataset.ciraPdf || '';
    if (!id) return;
    const ciraParam = (btn.dataset.ciraParam || '').replace(/[^a-z0-9]/gi, '');
    const qs = new URLSearchParams({id});
    if (ciraParam) qs.set('param', ciraParam);
    btn.disabled = true;
    const prev = btn.textContent;
    btn.textContent = 'Descargando...';
    fetch(`api/cira_pdf.php?${qs.toString()}`, {credentials:'same-origin'})
      .then(async resp => {
        const type = resp.headers.get('Content-Type') || '';
        if (!resp.ok || !type.includes('application/pdf')) {
          const msg = await resp.text().catch(() => '');
          throw new Error(msg || `HTTP ${resp.status}`);
        }
        return resp.blob();
      })
      .then(blob => {
        saveBlob(blob, `cira_${id}.pdf`);
        toast('PDF CIRA descargado correctamente', 'ok', 2600);
      })
      .catch(err => {
        toast('No se pudo descargar el PDF CIRA: ' + err.message, 'er', 6500);
      })
      .finally(() => {
        btn.disabled = false;
        btn.textContent = prev || 'Descargar PDF CIRA';
      });
  });
  function currentZonePayload(useAi=true) {
    const demand = Number(document.querySelector('.zone-demand-input')?.value || zoneState.last?.demanda_kw_usada || 0);
    const payload = {
      polygon: zoneState.points.map(p => [p.lat, p.lng]),
      use_ai: !!useAi,
    };
    if (demand > 0) payload.demanda_kw = demand;
    return payload;
  }
  function toLatLngPair(p) {
    if (!p) return null;
    const lat = Number(p.lat ?? p[0] ?? p.latitude);
    const lng = Number(p.lng ?? p[1] ?? p.lon ?? p.long ?? p.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
    return [lat, lng];
  }
  function polygonToGeoJson(points, props={}) {
    const ring = points.map(p => [Number(p.lng ?? p[1]), Number(p.lat ?? p[0])]);
    if (ring.length && (ring[0][0] !== ring[ring.length - 1][0] || ring[0][1] !== ring[ring.length - 1][1])) ring.push(ring[0]);
    return {type:'FeatureCollection', features:[{type:'Feature', properties:props, geometry:{type:'Polygon', coordinates:[ring]}}]};
  }
  function polygonToKml(points, name='Zona evaluada') {
    const coords = points.map(p => `${Number(p.lng ?? p[1]).toFixed(7)},${Number(p.lat ?? p[0]).toFixed(7)},0`);
    if (coords.length && coords[0] !== coords[coords.length - 1]) coords.push(coords[0]);
    return `<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>${esc(name)}</name>
<Style id="zone"><LineStyle><color>ff0f766e</color><width>2</width></LineStyle><PolyStyle><color>5514b8a6</color></PolyStyle></Style>
<Placemark><name>${esc(name)}</name><styleUrl>#zone</styleUrl><Polygon><outerBoundaryIs><LinearRing><coordinates>${coords.join(' ')}</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark>
</Document></kml>`;
  }
  async function requestZoneReport(format) {
    if (!zoneState.polygon || zoneState.points.length < 3) return toast('Primero dibuja o evalua una zona.', 'wa');
    toast(`Generando informe ${format.toUpperCase()}...`, 'in', 5000);
    const payload = {...currentZonePayload(false), format};
    const resp = await fetch('api/zone_report.php', {method:'POST', headers:csrfHeaders({'Content-Type':'application/json'}), body:JSON.stringify(payload)});
    if (!resp.ok) throw new Error(await resp.text());
    const blob = await resp.blob();
    const cd = resp.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/i);
    saveBlob(blob, m?.[1] || `informe_zona_${new Date().toISOString().slice(0,10)}.${format}`);
    toast(`Informe ${format.toUpperCase()} listo`, 'ok', 3500);
  }
  function downloadCurrentZone(kind) {
    if (!zoneState.polygon || zoneState.points.length < 3) return toast('Primero dibuja o evalua una zona.', 'wa');
    const name = `zona_${new Date().toISOString().slice(0,10)}`;
    if (kind === 'kml') saveBlob(new Blob([polygonToKml(zoneState.points, 'Zona evaluada')], {type:'application/vnd.google-earth.kml+xml'}), `${name}.kml`);
    if (kind === 'geojson') saveBlob(new Blob([JSON.stringify(polygonToGeoJson(zoneState.points, zoneState.last || {}), null, 2)], {type:'application/geo+json'}), `${name}.geojson`);
  }
  function renderCeraunicoSummary(da) {
    if (!da || typeof da !== 'object') return '';
    if (da.status && da.status !== 'success') {
      return `<div class="zone-relief"><strong>CERAUNICO</strong><span>${esc(da.message || 'No se pudo consultar CERAUNICO para esta geometria.')}</span></div>`;
    }
    const classes = Array.isArray(da.clases) ? da.clases : [];
    const dist = classes.slice(0, 6).map(c => `${esc(c.clase || '')}: ${f2(c.porcentaje,1)}% ${c.nivel ? `(${esc(c.nivel)})` : ''}`).join(' · ');
    return `<div class="zone-relief">
      <strong>CERAUNICO ${esc(da.nivel || '')}</strong>
      <span>Rayos totales por km2/año: promedio ${f2(da.promedio,2)}, maximo ${f2(da.max,2)}${da.clase_maxima ? `, clase ${esc(da.clase_maxima)}` : ''}.</span>
      <small>${dist || esc(da.comentario || 'Fuente OSINERGMIN CERAUNICO')}</small>
    </div>`;
  }
  function renderZoneAnalysis(data, id) {
    if (data.status === 'error') {
      return `<div class="zone-report" id="${id}"><div class="pu-st st-no">${esc(data.msg || data.message || 'No se pudo analizar la zona.')}</div></div>`;
    }
    const ind = data.indicadores || {};
    const relief = data.relieve || {};
    const sets = data.sets_cercanas || [];
    const reasons = (data.razones || []).map(r => `<li>${esc(r)}</li>`).join('');
    const samples = data.muestras || {};
    const ceraunicoBox = renderCeraunicoSummary(data.descargas_atmosfericas);
    const setRows = sets.map(s => `
      <div class="zone-set-row">
        <strong>${esc(s.nombre || 'SET')}</strong>
        <span>${esc(s.ubicacion || '')} - ${f2(s.distancia_km,2)} km</span>
        <em>${esc(s.estado || s.mensaje || 'Sin cálculo')}</em>
        <small>Cap. ${f2(s.capacidad_total_mva,1)} MVA · Dem. ${f2(s.demanda_total_mva,1)} MVA · Libre ${f2(s.disponible_total_mva,1)} MVA</small>
      </div>`).join('');
    const pitRows = (data.pit?.set_rows || []).map(p => {
      const spec = [
        p.potencia_mva != null && p.potencia_mva !== '' ? `${f2(p.potencia_mva,2)} MVA` : '',
        p.tension_kv != null && p.tension_kv !== '' ? `${esc(p.tension_kv)} kV` : '',
      ].filter(Boolean).join(' / ') || 's/d';
      return `
      <div class="zone-set-row">
        <strong>${esc(p.set || 'SET')}</strong>
        <span>${esc(p.pit_etiqueta || 'PIT')}</span>
        <em>${esc(p.estado || p.prioridad || 'Elemento PIT')}</em>
        <small>${esc(p.elemento || p.proyecto || 'Elemento')} - ${spec} - ${esc(p.fecha_detalle || p.fecha || 's/d')}</small>
      </div>`;
    }).join('');
    return `<div class="zone-report" id="${id}">
      <div class="ana-ttl">Viabilidad de zona</div>
      <div class="pu-st ${zoneStatusClass(data.viabilidad?.score)}">${esc(data.viabilidad?.nivel || 'Sin nivel')} · ${f2(data.viabilidad?.score,0)}/100</div>
      <div class="zone-grid">
        <div><span>Area</span><strong>${f2(data.area_km2,2)} km2</strong></div>
        <div><span>Demanda usada</span><strong>${f2(data.demanda_kw_usada,0)} kW</strong></div>
        <div><span>Trámite</span><strong>${esc(data.tramite_recomendado || 'Pendiente')}</strong></div>
        <div><span>Origen demanda</span><strong>${esc(data.demanda_origen || 'estimada')}</strong></div>
      </div>
      <label class="zone-demand">Demanda sustentada kW
        <input class="zone-demand-input" type="number" min="1" step="1" value="${esc(data.demanda_kw_usada || '')}">
      </label>
      <div class="zone-ind">
        <span>SET: ${fi(ind.sets_cercanas || 0)}</span>
        <span>LT: ${fi(ind.lineas_intersectan || 0)}</span>
        <span>MT/SED: ${fi((ind.mt_intersecta || 0) + (ind.sed_dentro || 0))}</span>
        <span>Concesiones: ${fi(ind.concesiones_intersectan || 0)}</span>
        <span>API ext.: ${fi(ind.externas_intersectan || 0)}</span>
      </div>
      ${relief.status === 'success' ? `<div class="zone-relief">
        <strong>Relieve ${esc(relief.riesgo || '')}</strong>
        <span>${esc(relief.descripcion || '')}</span>
        <small>Elevación ${f2(relief.min_m,0)}-${f2(relief.max_m,0)} m · fuente ${esc(relief.fuente || '')}</small>
      </div>` : ''}
      ${ceraunicoBox}
      ${setRows ? `<div class="zone-sets"><div>SET cercanas con disponibilidad</div>${setRows}</div>` : ''}
      ${pitRows ? `<div class="zone-sets"><div>PIT asociado a SET cercanas</div>${pitRows}</div>` : ''}
      ${reasons ? `<ul class="zone-reasons">${reasons}</ul>` : ''}
      ${samples.concesiones?.length ? `<div class="zone-sample">Concesiones: ${esc(samples.concesiones.slice(0,3).join(', '))}</div>` : ''}
      <div class="zone-actions">
        <button type="button" data-zone-recalc>Recalcular</button>
        <button type="button" data-zone-report="docx">Word</button>
        <button type="button" data-zone-report="pdf">PDF</button>
        <button type="button" data-zone-export="kml">KML</button>
        <button type="button" data-zone-export="geojson">GeoJSON</button>
        <button type="button" data-zone-clear>Cerrar zona</button>
      </div>
    </div>`;
  }
  function bindZonePopup(id, data) {
    const root = document.getElementById(id);
    if (!root) return;
    root.dataset.bound = '1';
  }
  document.addEventListener('click', e => {
    const btn = e.target?.closest?.('[data-zone-recalc],[data-zone-report],[data-zone-export],[data-zone-clear]');
    const root = btn?.closest?.('.zone-report');
    if (!btn || !root) return;
    e.preventDefault();
    e.stopPropagation();
    if (btn.matches('[data-zone-clear]')) {
      clearZoneAll();
      return;
    }
    if (btn.matches('[data-zone-report]')) {
      requestZoneReport(btn.dataset.zoneReport).catch(err => toast('No se pudo generar informe: ' + err.message, 'er', 6000));
      return;
    }
    if (btn.matches('[data-zone-export]')) {
      downloadCurrentZone(btn.dataset.zoneExport);
      return;
    }
    const demand = Number(root.querySelector('.zone-demand-input')?.value || 0);
    analyzeCurrentZone({demandaKw:demand});
  });
  document.addEventListener('click', e => {
    const btn = e.target?.closest?.('[data-opp-eval],[data-opp-report],[data-opp-export]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const idx = Number(btn.dataset.oppIdx ?? btn.dataset.oppEval);
    const c = zoneState.opportunityData[idx];
    if (!c || !setZoneFromPolygon(c.polygon, c)) return toast('No se pudo leer el poligono sugerido.', 'er');
    mapInstance.fitBounds(zoneState.polygon.getBounds(), {padding:[24,24], maxZoom:13});
    if (btn.matches('[data-opp-eval]')) {
      analyzeCurrentZone({demandaKw:c.demanda_kw_referencia});
      return;
    }
    if (btn.matches('[data-opp-report]')) {
      requestZoneReport(btn.dataset.oppReport).catch(err => toast('No se pudo generar informe: ' + err.message, 'er', 6000));
      return;
    }
    if (btn.matches('[data-opp-export]')) downloadCurrentZone(btn.dataset.oppExport);
  });
  async function analyzeCurrentZone(opts={}) {
    if (!zoneState.polygon || zoneState.points.length < 3) return;
    const id = `zone-report-${++zoneState.seq}`;
    zoneState.polygon.bindPopup(`<div class="zone-report" id="${id}"><div class="pu-st st-warn"><span class="small-spinner"></span> Analizando zona...</div></div>`, {maxWidth:460}).openPopup();
    const payload = {
      polygon: zoneState.points.map(p => [p.lat, p.lng]),
      use_ai: !!opts.useAi,
    };
    if (Number(opts.demandaKw) > 0) payload.demanda_kw = Number(opts.demandaKw);
    try {
      const resp = await fetch('api/zone_analysis.php', {
        method:'POST',
        headers:csrfHeaders({'Content-Type':'application/json'}),
        body:JSON.stringify(payload),
      });
      const data = await readJsonResponse(resp);
      zoneState.last = data;
      zoneState.polygon.bindPopup(renderZoneAnalysis(data, id), {maxWidth:460}).openPopup();
      setTimeout(() => bindZonePopup(id, data), 60);
      toast(data.status === 'success' ? 'Zona analizada' : 'No se pudo analizar la zona', data.status === 'success' ? 'ok' : 'er', 3500);
    } catch (err) {
      const data = {status:'error', msg:err.message};
      zoneState.polygon.bindPopup(renderZoneAnalysis(data, id), {maxWidth:460}).openPopup();
    }
  }
  function finishZoneDraw() {
    if (zoneState.points.length < 3) {
      clearZoneDraft();
      toast('La zona necesita al menos 3 puntos.', 'wa', 2800);
      return;
    }
    if (zoneState.polygon) zoneState.layer.removeLayer(zoneState.polygon);
    const latlngs = zoneState.points.map(p => [p.lat, p.lng]);
    clearZoneDraft();
    zoneState.polygon = L.polygon(latlngs, {
      color:'#0f766e',
      weight:2,
      fillColor:'#14b8a6',
      fillOpacity:.22,
    }).addTo(zoneState.layer);
    setZoneDrawMode(false);
    analyzeCurrentZone();
  }
  function setZoneFromPolygon(points, data=null) {
    if (!Array.isArray(points) || points.length < 3) return false;
    if (zoneState.polygon) zoneState.layer.removeLayer(zoneState.polygon);
    zoneState.points = points.map(toLatLngPair).filter(Boolean).map(p => L.latLng(p[0], p[1]));
    if (zoneState.points.length < 3) return false;
    zoneState.polygon = L.polygon(zoneState.points.map(p => [p.lat, p.lng]), {
      color:'#0f766e',
      weight:2.4,
      fillColor:'#14b8a6',
      fillOpacity:.22,
    }).addTo(zoneState.layer);
    zoneState.last = data || null;
    return true;
  }
  function renderOpportunity(c, idx) {
    if (c.request_geometry) {
      const anchor = c.entidad_ancla || {};
      return `<div class="zone-report">
        <div class="ana-ttl">${esc(c.nombre || 'Geometría solicitada')}</div>
        <div class="pu-st st-ok">Trazado solicitado por el usuario</div>
        <div class="zone-grid">
          <div><span>Entidad ancla</span><strong>${esc(anchor.nombre || 'Entidad identificada')}</strong></div>
          <div><span>Tipo</span><strong>${esc(anchor.tipo || 'Capa de plataforma')}</strong></div>
          <div><span>Fuente</span><strong>${esc(anchor.fuente || 'Geovisor')}</strong></div>
          <div><span>Radio ref.</span><strong>${f2(c.radio_referencia_km,3)} km</strong></div>
        </div>
        <div class="line-note">${esc(c.razon || 'Geometría preliminar pendiente de validación territorial.')}</div>
        <div class="zone-actions">
          <button type="button" data-opp-eval="${idx}">Evaluar zona</button>
          <button type="button" data-opp-report="pdf" data-opp-idx="${idx}">PDF</button>
          <button type="button" data-opp-export="kml" data-opp-idx="${idx}">KML</button>
          <button type="button" data-opp-export="geojson" data-opp-idx="${idx}">GeoJSON</button>
        </div>
      </div>`;
    }
    const set = c.set_referencia || {};
    const hasSet = !!(set && (set.nombre || set.disponible_total_mva));
    const mode = c.modo_suministro || (hasSet ? 'distribucion_desde_set_disponible' : 'generacion_local_sin_set_cercana');
    const genAlts = Array.isArray(c.alternativas_generacion) ? c.alternativas_generacion.slice(0, 5) : [];
    const generation = c.fuente_generacion_recomendada || genAlts[0]?.fuente || '';
    const genName = genAlts[0]?.nombre || generation || 'por evaluar';
    const supportCells = hasSet
      ? `<div><span>SET ref.</span><strong>${esc(set.nombre || 'SET')}</strong></div>
        <div><span>Libre SET</span><strong>${f2(set.disponible_total_mva,1)} MVA</strong></div>`
      : `<div><span>Fuente sugerida</span><strong>${esc(genName)}</strong></div>
        <div><span>Region</span><strong>${esc(c.macro_region || 'referencial')}</strong></div>`;
    const genList = genAlts.length ? `<div class="zone-gen-list">
      ${genAlts.map(a => `<div class="zone-gen-row">
        <strong>${esc(a.nombre || a.fuente || 'Generación')}</strong>
        <span>${esc(a.prioridad || '')} · ${f2(a.score,0)}/100</span>
        <small>${esc(a.razon || a.condicion || '')}</small>
      </div>`).join('')}
    </div>` : '';
    return `<div class="zone-report">
      <div class="ana-ttl">${esc(c.nombre || 'Zona sugerida')}</div>
      <div class="pu-st ${zoneStatusClass(c.score)}">${esc(c.viabilidad || 'Media')} · ${f2(c.score,0)}/100</div>
      <div class="zone-grid">
        <div><span>Demanda ref.</span><strong>${f2(c.demanda_kw_referencia,0)} kW</strong></div>
        <div><span>Trámite</span><strong>${esc(c.tramite_recomendado || '')}</strong></div>
        <div><span>Modo</span><strong>${esc(mode === 'generacion_local_sin_set_cercana' ? 'Generación local' : 'Distribución desde SET')}</strong></div>
        ${supportCells}
        ${hasSet ? `<div><span>Gen. sugerida</span><strong>${esc(genName)}</strong></div>` : ''}
        <div><span>Líneas cercanas</span><strong>${fi(c.lineas_cercanas || 0)}</strong></div>
        <div><span>Concesión</span><strong>No detectada</strong></div>
        <div><span>API externa</span><strong>${fi(c.capas_externas_cercanas || 0)}</strong></div>
        <div><span>Productivo</span><strong>${fi(c.evidencia_productiva || 0)} señales</strong></div>
      </div>
      ${c.fuentes_productivas?.length ? `<div class="zone-sample">Evidencia: ${esc(c.fuentes_productivas.slice(0,3).join(', '))}</div>` : ''}
      ${genList}
      ${c.razon_generacion ? `<div class="line-note">${esc(c.razon_generacion)}</div>` : ''}
      <div class="zone-actions">
        <button type="button" data-opp-eval="${idx}">Evaluar zona</button>
        <button type="button" data-opp-report="docx" data-opp-idx="${idx}">Word</button>
        <button type="button" data-opp-report="pdf" data-opp-idx="${idx}">PDF</button>
        <button type="button" data-opp-export="kml" data-opp-idx="${idx}">KML</button>
        <button type="button" data-opp-export="geojson" data-opp-idx="${idx}">GeoJSON</button>
      </div>
      <div class="analysis-source">${esc(c.razon || '')}</div>
    </div>`;
  }
  function drawOpportunityCandidates(candidates, fit=true) {
    if (zoneState.opportunities) zoneState.layer.removeLayer(zoneState.opportunities);
    zoneState.opportunityData = Array.isArray(candidates) ? candidates : [];
    const group = L.featureGroup();
    zoneState.opportunityData.forEach((c, idx) => {
      const pts = (c.polygon || []).map(toLatLngPair).filter(Boolean);
      if (pts.length < 3) return;
      const generation = c.modo_suministro === 'generacion_local_sin_set_cercana';
      const requested = !!c.request_geometry;
      const poly = L.polygon(pts, {
        color:requested ? '#1565c0' : (generation ? '#7c3aed' : (c.score >= 70 ? '#16a34a' : '#f59e0b')),
        fillColor:requested ? '#42a5f5' : (generation ? '#a855f7' : (c.score >= 70 ? '#22c55e' : '#fbbf24')),
        fillOpacity:.20,
        weight:2.2,
      }).bindPopup(renderOpportunity(c, idx), {maxWidth:440});
      group.addLayer(poly);
    });
    zoneState.opportunities = group.addTo(zoneState.layer);
    if (fit && group.getLayers().length) {
      const exact = zoneState.opportunityData.some(candidate => candidate?.request_geometry);
      mapInstance.fitBounds(group.getBounds(), {padding:[24,24], maxZoom:exact ? 15 : 10});
    }
    return group.getLayers().length;
  }
  async function loadZoneOpportunities(useAi=false) {
    toast('Buscando zonas sugeridas...', 'in', 3500);
    try {
      const seed = Math.floor(Date.now() / (6 * 60 * 60 * 1000)) + (++zoneState.suggestSeq - 1);
      const url = `api/zone_analysis.php?mode=opportunities&use_ai=${useAi ? '1' : '0'}&incluir_generacion=1&seed=${encodeURIComponent(seed)}`;
      let data = null;
      for (let attempt = 0; attempt < 75; attempt++) {
        const resp = await fetch(url, {cache:'no-store'});
        data = await readJsonResponse(resp);
        if (data.status !== 'processing') break;
        if (attempt === 0) toast('Preparando el análisis en segundo plano; puede continuar usando el mapa.', 'in', 6500);
        await new Promise(resolve => setTimeout(resolve, Math.max(2, Number(data.retry_after || 4)) * 1000));
      }
      if (data?.status === 'processing') throw new Error('El análisis sigue procesándose. Intente nuevamente en unos minutos.');
      if (data.status !== 'success') throw new Error(data.msg || 'Sin respuesta');
      drawOpportunityCandidates(data.candidatos || [], true);
      toast(`Zonas sugeridas: ${(data.candidatos || []).length}`, 'ok', 3600);
    } catch (err) {
      toast('No se pudieron sugerir zonas: ' + err.message, 'er', 5000);
    }
  }

  document.getElementById('dockDraw')?.addEventListener('click', () => setZoneDrawMode(!zoneState.active));
  document.getElementById('dockClearZone')?.addEventListener('click', () => clearZoneAll());
  document.getElementById('dockZones')?.addEventListener('click', () => loadZoneOpportunities(false));

  const tempGeoPanel = document.getElementById('tempGeoPanel');
  const tempGeoForm = document.getElementById('tempGeoForm');
  const tempGeoFile = document.getElementById('tempGeoFile');
  const tempGeoSummary = document.getElementById('tempGeoSummary');
  const tempGeoModeEl = document.getElementById('tempGeoMode') || document.getElementById('tempLineType');
  const tempLineDefaults = document.getElementById('tempLineDefaults');
  function tempMode() {
    return tempGeoModeEl?.value || 'multiple';
  }
  function tempIsLineMode(mode=tempMode()) {
    return mode === 'transmision' || mode === 'distribucion';
  }
  function tempGlobalMeta() {
    const mode = tempMode();
    return {
      mode,
      lineType:tempIsLineMode(mode) ? mode : 'distribucion',
      voltageKv:Number(document.getElementById('tempVoltageKv')?.value || 0),
      circuit:document.getElementById('tempCircuitType')?.value || 'simple',
      enabled:true,
    };
  }
  function tempFeatureKey(feature, idx=0) {
    if (!feature) return `temp-${idx}`;
    if (!feature.id) feature.id = `temp-local-${++tempGeoState.seq}`;
    return feature.id;
  }
  function tempFeatureKind(feature) {
    const t = feature?.geometry?.type || '';
    if (t === 'LineString') return 'linea';
    if (t === 'Polygon') return 'poligono';
    if (t === 'Point') return 'punto';
    return 'geometria';
  }
  function tempDefaultMeta(feature) {
    const base = tempGlobalMeta();
    const kind = tempFeatureKind(feature);
    const visible = String(feature?.properties?.visibility ?? '1') !== '0';
    if (kind === 'linea') {
      return {enabled:true, visible, analysisType:'linea', lineType:base.lineType, voltageKv:base.voltageKv, circuit:base.circuit};
    }
    if (kind === 'poligono') return {enabled:true, visible, analysisType:'poligono'};
    return {enabled:false, visible, analysisType:kind};
  }
  function tempEnsureMeta(feature, idx=0) {
    const id = tempFeatureKey(feature, idx);
    if (!tempGeoState.metaById.has(id)) tempGeoState.metaById.set(id, tempDefaultMeta(feature));
    return tempGeoState.metaById.get(id);
  }
  function tempMeta(feature) {
    return tempEnsureMeta(feature);
  }
  function tempSyncLineDefaultsVisibility() {
    if (!tempLineDefaults) return;
    const mode = tempMode();
    tempLineDefaults.hidden = mode === 'poligono';
    tempLineDefaults.classList.toggle('is-hidden', mode === 'poligono');
  }
  function tempPoints(feature) {
    return (feature?.geometry?.coordinates || []).map(toLatLngPair).filter(Boolean);
  }
  function tempTypeLabel(feature) {
    const t = feature?.geometry?.type || '';
    if (t === 'Polygon') return 'Polígono';
    if (t === 'LineString') return 'Línea';
    if (t === 'Point') return 'Punto';
    return 'Geometría';
  }
  function tempLineKm(points) {
    let km = 0;
    for (let i = 1; i < points.length; i++) km += L.latLng(points[i - 1][0], points[i - 1][1]).distanceTo(L.latLng(points[i][0], points[i][1])) / 1000;
    return km;
  }
  function tempConductor(meta) {
    const kv = Number(meta.voltageKv || 0);
    if (kv <= 35) return meta.lineType === 'distribucion' ? 'AAAC/ACSR 70-150 mm2, validar caída de tensión y cortocircuito.' : 'ACSR/AAAC 150-240 mm2, validar ampacidad y flecha.';
    if (kv <= 69) return 'ACSR/AAAC 185-300 mm2, validar corriente, pérdidas y coordinación de aislamiento.';
    if (kv <= 138) return 'ACSR/AAAC 240-400 mm2, evaluar OPGW, apantallamiento y servidumbre.';
    return 'ACSR/AAAC de alta capacidad o haz conductor, sujeto a estudio de flujo, corona y servidumbre.';
  }
  function tempStructure(meta) {
    const kv = Number(meta.voltageKv || 0);
    if (meta.lineType === 'distribucion' || kv <= 35) return 'Postes CAC, madera tratada o metálicos; usar retención/ángulo donde cambie la ruta.';
    if (kv <= 69) return 'Postes metálicos o estructuras reticuladas livianas según vano, acceso y franja.';
    return 'Torres reticuladas o monopostes; definir vanos, cimentación, puesta a tierra y servidumbre.';
  }
  function tempBounds(features) {
    const all = [];
    features.forEach(f => tempPoints(f).forEach(p => all.push([p[0], p[1]])));
    return all.length ? L.latLngBounds(all) : null;
  }
  function tempPayload(feature, format='json') {
    const meta = tempMeta(feature);
    const isLine = feature?.geometry?.type === 'LineString';
    const payload = {
      format,
      name:feature?.name || 'Geometría temporal',
      geometry:{
        type:feature?.geometry?.type || '',
        coordinates:tempPoints(feature).map(p => [p[0], p[1]]),
      },
      meta:{
        analysis_type:meta.analysisType || tempFeatureKind(feature),
        enabled:meta.enabled !== false,
        line_type:isLine ? meta.lineType : '',
        voltage_kv:isLine ? meta.voltageKv : '',
        circuit:isLine ? meta.circuit : '',
      },
    };
    const cached = tempGeoState.analysisById.get(feature?.id || '');
    if (cached?.analysis_cache_key) payload.analysis_cache_key = cached.analysis_cache_key;
    return payload;
  }
  function tempList(items, empty='Sin cruces detectados') {
    if (!Array.isArray(items) || !items.length) return `<span>${esc(empty)}</span>`;
    return `<ul class="zone-reasons">${items.slice(0, 8).map(x => `<li>${esc(x.nombre || x.name || 'Elemento')} ${x.distancia_km != null ? `(${f2(x.distancia_km,2)} km)` : ''}</li>`).join('')}</ul>`;
  }
  function renderTempServerAnalysis(data, feature) {
    const a = data.analysis || data;
    const ceraunicoBox = renderCeraunicoSummary(a.descargas_atmosfericas);
    if (a.tipo === 'linea') {
      const h = a.hallazgos || {};
      const risks = (a.tecnico?.riesgos || []).map(r => `<li>${esc(r)}</li>`).join('');
      return `<div class="ana-res">
        <div class="ana-ttl">Análisis completo de archivo geográfico</div>
        <div class="temp-route-box">
          <div><span>Longitud</span><strong>${f2(a.longitud_km,3)} km</strong></div>
          <div><span>Tensión</span><strong>${f2(a.especificacion?.tension_kv,1)} kV</strong></div>
          <div><span>Tipo</span><strong>${esc(a.especificacion?.tipo_linea || '')}</strong></div>
          <div><span>Terna</span><strong>${esc(a.especificacion?.terna || '')}</strong></div>
        </div>
        <div class="line-note"><strong>Parte aproximadamente desde:</strong> ${esc(a.punto_inicio?.referencia || '')}</div>
        <div class="line-note"><strong>Llega aproximadamente a:</strong> ${esc(a.punto_fin?.referencia || '')}</div>
        <div class="line-note"><strong>Conductor:</strong> ${esc(a.tecnico?.conductor_referencial || '')}</div>
        <div class="line-note"><strong>Estructura:</strong> ${esc(a.tecnico?.estructura_referencial || '')}</div>
        ${ceraunicoBox}
        <div class="pit-box"><div class="ana-ttl">Concesiones vigentes/en trámite</div>${tempList(h.concesiones)}</div>
        <div class="pit-box"><div class="ana-ttl">Por donde pasa</div>
          <div class="zone-grid">
            <div><span>LT cruzadas</span><strong>${fi((h.lt || []).length)}</strong></div>
            <div><span>MT cruzada</span><strong>${fi((h.mt || []).length)}</strong></div>
            <div><span>SET cercanas</span><strong>${fi((h.sets || []).length)}</strong></div>
            <div><span>SED cercanas</span><strong>${fi((h.sed || []).length)}</strong></div>
          </div>
          ${tempList([...(h.lt || []), ...(h.mt || []), ...(h.sets || []), ...(h.sed || []), ...(h.externas || [])], 'Sin otros cruces relevantes en capas cargadas')}
        </div>
        ${risks ? `<ul class="zone-reasons">${risks}</ul>` : ''}
        <div class="zone-actions">
          <button type="button" data-temp-report="docx" data-temp-report-id="${esc(feature.id || '')}">Word</button>
          <button type="button" data-temp-report="pdf" data-temp-report-id="${esc(feature.id || '')}">PDF</button>
          <button type="button" data-temp-ai="${esc(feature.id || '')}">IA técnica</button>
        </div>
      </div>`;
    }
    const ind = a.indicadores || {};
    const samples = a.muestras || {};
    const reasons = (a.razones || []).map(r => `<li>${esc(r)}</li>`).join('');
    const setRows = (a.sets_cercanas || []).map(s => `
      <div class="zone-set-row">
        <strong>${esc(s.nombre || 'SET')}</strong>
        <span>${esc(s.ubicacion || '')} - ${f2(s.distancia_km,2)} km</span>
        <em>${esc(s.estado || s.mensaje || s.status || 'Sin calculo')}</em>
        <small>Cap. ${f2(s.capacidad_total_mva,2)} MVA - Dem. ${f2(s.demanda_total_mva,2)} MVA - Libre ${f2(s.disponible_total_mva,2)} MVA</small>
      </div>`).join('');
    const pitRows = (a.pit?.set_rows || []).map(p => {
      const spec = [
        p.potencia_mva != null && p.potencia_mva !== '' ? `${f2(p.potencia_mva,2)} MVA` : '',
        p.tension_kv != null && p.tension_kv !== '' ? `${esc(p.tension_kv)} kV` : '',
      ].filter(Boolean).join(' / ') || 's/d';
      return `
      <div class="zone-set-row">
        <strong>${esc(p.set || 'SET')}</strong>
        <span>${esc(p.pit_etiqueta || 'PIT')}</span>
        <em>${esc(p.estado || p.prioridad || 'Elemento PIT')}</em>
        <small>${esc(p.elemento || p.proyecto || 'Elemento')} - ${spec} - ${esc(p.fecha_detalle || p.fecha || 's/d')}</small>
      </div>`;
    }).join('');
    return `<div class="ana-res">
      <div class="ana-ttl">Análisis completo de archivo geográfico</div>
      <div class="pu-st ${zoneStatusClass(a.viabilidad?.score)}">${esc(a.viabilidad?.nivel || 'Sin nivel')} · ${f2(a.viabilidad?.score,0)}/100</div>
      <div class="zone-grid">
        <div><span>Área</span><strong>${f2(a.area_km2,3)} km2</strong></div>
        <div><span>Trámite</span><strong>${esc(a.tramite_recomendado || '')}</strong></div>
        <div><span>Concesiones</span><strong>${fi(ind.concesiones_intersectan || 0)}</strong></div>
        <div><span>SET cercanas</span><strong>${fi(ind.sets_cercanas || 0)}</strong></div>
      </div>
      <div class="line-note"><strong>Parte aproximadamente desde:</strong> ${esc(a.punto_inicio?.referencia || '')}</div>
      ${ceraunicoBox}
      <div class="pit-box"><div class="ana-ttl">Concesiones vigentes/en trámite</div>${tempList((samples.concesiones || []).map(nombre => ({nombre})))}</div>
      ${setRows ? `<div class="zone-sets"><div>SET cercanas con disponibilidad</div>${setRows}</div>` : ''}
      ${pitRows ? `<div class="zone-sets"><div>PIT asociado a SET cercanas</div>${pitRows}</div>` : ''}
      ${reasons ? `<ul class="zone-reasons">${reasons}</ul>` : ''}
      <div class="zone-actions">
        <button type="button" data-temp-report="docx" data-temp-report-id="${esc(feature.id || '')}">Word</button>
        <button type="button" data-temp-report="pdf" data-temp-report-id="${esc(feature.id || '')}">PDF</button>
      </div>
    </div>`;
  }
  async function analyzeTempOnServer(feature) {
    if (tempMeta(feature).enabled === false) return toast('Active esta geometría para evaluarla.', 'wa', 3200);
    const cached = tempGeoState.analysisById.get(feature?.id || '');
    if (cached) {
      openAnalysisPanel(`${tempTypeLabel(feature)} temporal - ${feature.name || 'Archivo geográfico'}`, renderTempServerAnalysis(cached, feature), 'Archivo geográfico temporal · resultado reutilizado');
      return cached;
    }
    openAnalysisPanel(`${tempTypeLabel(feature)} temporal - ${feature.name || 'Archivo geográfico'}`, '<div class="ana-res"><div class="pu-st st-warn"><span class="small-spinner"></span> Analizando geometría temporal...</div></div>', 'Archivo geográfico temporal');
    const resp = await fetch('api/temp_geo_report.php', {method:'POST', headers:csrfHeaders({'Content-Type':'application/json'}), body:JSON.stringify(tempPayload(feature, 'json'))});
    const data = await readJsonResponse(resp);
    if (!data.ok) throw new Error(data.message || data.msg || `HTTP ${resp.status}`);
    tempGeoState.analysisById.set(feature?.id || '', data);
    openAnalysisPanel(`${tempTypeLabel(feature)} temporal - ${feature.name || 'Archivo geográfico'}`, renderTempServerAnalysis(data, feature), 'Archivo geográfico temporal');
    return data;
  }
  async function downloadTempReport(feature, format) {
    if (tempMeta(feature).enabled === false) return toast('Active esta geometría antes de exportarla.', 'wa', 3200);
    toast(`Generando informe ${format.toUpperCase()} temporal...`, 'in', 4200);
    const resp = await fetch('api/temp_geo_report.php', {method:'POST', headers:csrfHeaders({'Content-Type':'application/json'}), body:JSON.stringify(tempPayload(feature, format))});
    const type = resp.headers.get('Content-Type') || '';
    if (!resp.ok || (!type.includes('application/pdf') && !type.includes('wordprocessingml'))) {
      const msg = await resp.text().catch(() => '');
      throw new Error(msg || `HTTP ${resp.status}`);
    }
    const blob = await resp.blob();
    const cd = resp.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/i);
    saveBlob(blob, m?.[1] || `informe_temporal_kml_${new Date().toISOString().slice(0,10)}.${format}`);
    toast(`Informe ${format.toUpperCase()} temporal listo`, 'ok', 3000);
  }
  function renderTempLineAnalysis(feature) {
    const pts = tempPoints(feature);
    const meta = tempMeta(feature);
    const km = tempLineKm(pts);
    const type = meta.lineType === 'distribucion' ? 'Distribución' : 'Transmisión';
    const circuit = meta.circuit === 'doble' ? 'Doble terna' : 'Simple terna';
    return `<div class="ana-res">
      <div class="ana-ttl">Ruta de archivo geográfico temporal</div>
      <div class="temp-route-box">
        <div><span>Longitud</span><strong>${f2(km,2)} km</strong></div>
        <div><span>Tensión</span><strong>${f2(meta.voltageKv,1)} kV</strong></div>
        <div><span>Tipo</span><strong>${esc(type)}</strong></div>
        <div><span>Configuración</span><strong>${esc(circuit)}</strong></div>
      </div>
      <div class="line-note"><strong>Conductor referencial:</strong> ${esc(tempConductor(meta))}</div>
      <div class="line-note"><strong>Estructura referencial:</strong> ${esc(tempStructure(meta))}</div>
      <div class="line-note">Validar relieve, accesos, cruces, servidumbre, interferencias ambientales, zonas arqueológicas y disponibilidad en SET antes de cerrar la ingeniería.</div>
      <div class="zone-actions">
        <button type="button" data-temp-full="${esc(feature.id || '')}">Análisis completo</button>
        <button type="button" data-temp-report="docx" data-temp-report-id="${esc(feature.id || '')}">Word</button>
        <button type="button" data-temp-report="pdf" data-temp-report-id="${esc(feature.id || '')}">PDF</button>
        <button type="button" data-temp-ai="${esc(feature.id || '')}">IA técnica</button>
      </div>
    </div>`;
  }
  function renderTempSummary() {
    if (!tempGeoSummary) return;
    if (!tempGeoState.features.length) {
      tempGeoSummary.innerHTML = '';
      return;
    }
    const html = tempGeoState.features.map((f, idx) => {
      const id = tempFeatureKey(f, idx);
      const meta = tempEnsureMeta(f, idx);
      const pts = tempPoints(f);
      const isLine = f.geometry?.type === 'LineString';
      const isPoly = f.geometry?.type === 'Polygon';
      const canReport = isLine || isPoly;
      const detail = isLine ? `${f2(tempLineKm(pts),2)} km` : `${pts.length} puntos`;
      const enabled = meta.enabled !== false;
      const disabledAttr = enabled ? '' : 'disabled';
      const lineControls = isLine ? `<div class="temp-line-controls" data-temp-controls="${esc(id)}">
          <label><span>Tipo</span><select data-temp-line-type="${esc(id)}">
            <option value="transmision" ${meta.lineType === 'transmision' ? 'selected' : ''}>Transmisión</option>
            <option value="distribucion" ${meta.lineType === 'distribucion' ? 'selected' : ''}>Distribución</option>
          </select></label>
          <label><span>kV</span><input type="number" min="1" step="0.1" value="${esc(meta.voltageKv || '')}" data-temp-voltage="${esc(id)}"></label>
          <label><span>Terna</span><select data-temp-circuit="${esc(id)}">
            <option value="simple" ${meta.circuit !== 'doble' ? 'selected' : ''}>Simple</option>
            <option value="doble" ${meta.circuit === 'doble' ? 'selected' : ''}>Doble</option>
          </select></label>
        </div>` : '';
      const badge = isLine ? `${meta.lineType === 'transmision' ? 'Transmisión' : 'Distribución'} · ${f2(meta.voltageKv,1)} kV · ${meta.circuit === 'doble' ? 'Doble terna' : 'Simple terna'}` : (isPoly ? 'Polígono sin tensión/terna' : 'Referencia temporal');
      return `<div class="temp-card ${enabled ? '' : 'is-off'}">
        <div class="temp-card-top">
          <div>
            <strong>${esc(f.name || `Elemento ${idx + 1}`)}</strong>
            <span>${esc(tempTypeLabel(f))} · ${esc(detail)}</span>
          </div>
          <label class="temp-eval-toggle" title="Activar o desactivar esta geometría para evaluación IA e informes">
            <input type="checkbox" data-temp-enabled="${esc(id)}" ${enabled ? 'checked' : ''}>
            <span>Evaluar</span>
          </label>
        </div>
        <small>${esc(badge)}</small>
        <small>${esc(f.properties?.source_file || '')}</small>
        ${lineControls}
        <div class="temp-card-actions">
          <button type="button" class="temp-mini-btn" data-temp-zoom="${idx}">Acercar</button>
          ${canReport ? `<button type="button" class="temp-mini-btn" data-temp-full="${esc(id)}" ${disabledAttr}>Analizar</button>
          <button type="button" class="temp-mini-btn" data-temp-report="docx" data-temp-report-id="${esc(id)}" ${disabledAttr}>Word</button>
          <button type="button" class="temp-mini-btn" data-temp-report="pdf" data-temp-report-id="${esc(id)}" ${disabledAttr}>PDF</button>
          <button type="button" class="temp-mini-btn alt" data-temp-ai="${esc(id)}" ${disabledAttr}>IA</button>` : '<button type="button" class="temp-mini-btn" disabled>Punto referencial</button>'}
        </div>
      </div>`;
    }).join('');
    const notice = tempGeoState.notice
      ? `<div class="line-note temp-file-notice">${esc(tempGeoState.notice)}</div>`
      : '';
    tempGeoSummary.innerHTML = notice + html;
  }
  function drawTempFeatures(features, resetMeta=true, fit=true) {
    tempGeoState.layer.clearLayers();
    tempGeoState.layerById.clear();
    tempGeoState.features = Array.isArray(features) ? features : [];
    if (resetMeta) {
      tempGeoState.metaById.clear();
      tempGeoState.analysisById.clear();
      tempGeoState.pendingById.clear();
    }
    tempGeoState.features.forEach((feature, idx) => {
      const id = tempFeatureKey(feature, idx);
      const meta = tempEnsureMeta(feature, idx);
      const enabled = meta.enabled !== false;
      if (meta.visible === false) return;
      const pts = tempPoints(feature);
      if (!pts.length) return;
      const latlngs = pts.map(p => [p[0], p[1]]);
      let layer = null;
      if (feature.geometry?.type === 'Polygon' && pts.length >= 3) {
        layer = L.polygon(latlngs, {color:enabled ? '#0f766e' : '#94a3b8', weight:2.2, dashArray:enabled ? null : '6 5', fillColor:enabled ? '#14b8a6' : '#cbd5e1', fillOpacity:enabled ? .18 : .08, opacity:enabled ? .92 : .55});
      } else if (feature.geometry?.type === 'LineString' && pts.length >= 2) {
        layer = L.polyline(latlngs, {color:enabled ? '#7c3aed' : '#94a3b8', weight:3.2, opacity:enabled ? .92 : .55, dashArray:enabled ? null : '7 6'});
      } else if (feature.geometry?.type === 'Point') {
        layer = L.circleMarker(latlngs[0], {radius:6, color:enabled ? '#7c3aed' : '#94a3b8', weight:2, fillColor:enabled ? '#a78bfa' : '#cbd5e1', fillOpacity:enabled ? .82 : .45});
      }
      if (!layer) return;
      const disabledAttr = enabled ? '' : 'disabled';
      const reportActions = feature.geometry?.type === 'Point'
        ? '<span class="line-note">Punto referencial incluido en el archivo temporal.</span>'
        : `<button type="button" data-temp-full="${esc(id)}" ${disabledAttr}>Analizar</button><button type="button" data-temp-report="docx" data-temp-report-id="${esc(id)}" ${disabledAttr}>Word</button><button type="button" data-temp-report="pdf" data-temp-report-id="${esc(id)}" ${disabledAttr}>PDF</button>`;
      layer.bindPopup(`<div class="zone-report"><div class="ana-ttl">${esc(tempTypeLabel(feature))} temporal</div><strong>${esc(feature.name || `Elemento ${idx + 1}`)}</strong><div class="zone-actions"><button type="button" data-temp-zoom="${idx}">Acercar</button>${reportActions}</div></div>`, {maxWidth:360});
      layer.on('click', () => {
        tempGeoState.selected = id;
        document.dispatchEvent(new CustomEvent('cvc:geo-feature-selected', {detail:{id, feature, index:idx}}));
      });
      layer.addTo(tempGeoState.layer);
      tempGeoState.layerById.set(id, layer);
    });
    const bounds = tempBounds(tempGeoState.features);
    if (fit && bounds?.isValid?.()) mapInstance.fitBounds(bounds, {padding:[24,24], maxZoom:14});
    renderTempSummary();
  }
  function clearTempGeo() {
    tempGeoState.layer.clearLayers();
    tempGeoState.features = [];
    tempGeoState.layerById.clear();
    tempGeoState.metaById.clear();
    tempGeoState.analysisById.clear();
    tempGeoState.pendingById.clear();
    tempGeoState.selected = null;
    tempGeoState.notice = '';
    if (tempGeoSummary) tempGeoSummary.innerHTML = '';
    if (tempGeoFile) tempGeoFile.value = '';
    document.getElementById('dockTempGeo')?.classList.remove('on');
    toast('Archivo temporal limpio', 'ok', 1800);
  }
  function tempFeatureById(id) {
    const idx = tempGeoState.features.findIndex(f => f.id === id);
    return {feature:idx >= 0 ? tempGeoState.features[idx] : null, idx};
  }
  function tempRefreshLoadedFeatures() {
    drawTempFeatures(tempGeoState.features, false, false);
  }
  function evaluateTempFeature(idx) {
    const feature = tempGeoState.features[idx];
    if (!feature) return;
    if (tempMeta(feature).enabled === false) return toast('Active esta geometría para evaluarla.', 'wa', 3200);
    const pts = tempPoints(feature);
    if (feature.geometry?.type === 'Polygon') {
      if (!setZoneFromPolygon(pts, {nombre:feature.name || 'Zona temporal'})) return toast('No se pudo leer el poligono temporal.', 'er');
      const bounds = tempBounds([feature]);
      if (bounds?.isValid?.()) mapInstance.fitBounds(bounds, {padding:[24,24], maxZoom:feature.geometry?.type === 'Polygon' ? 14 : 13});
      analyzeTempOnServer(feature).catch(err => toast('No se pudo analizar temporal: ' + err.message, 'er', 6500));
      return;
    }
    if (feature.geometry?.type === 'Polygon') {
      if (!setZoneFromPolygon(pts, {nombre:feature.name || 'Zona temporal'})) return toast('No se pudo leer el polígono temporal.', 'er');
      mapInstance.fitBounds(zoneState.polygon.getBounds(), {padding:[24,24], maxZoom:14});
      analyzeCurrentZone({useAi:false});
      return;
    }
    if (feature.geometry?.type === 'LineString') {
      openAnalysisPanel(`Línea temporal - ${feature.name || 'Archivo geográfico'}`, renderTempLineAnalysis(feature), 'Ruta externa temporal');
      const bounds = tempBounds([feature]);
      if (bounds?.isValid?.()) mapInstance.fitBounds(bounds, {padding:[24,24], maxZoom:13});
    }
  }
  function tempPolygonAreaKm2(points) {
    if (!Array.isArray(points) || points.length < 3) return 0;
    const lat0 = points.reduce((s, p) => s + Number(p[0] || 0), 0) / points.length * Math.PI / 180;
    const xy = points.map(p => [Number(p[1] || 0) * 111.320 * Math.cos(lat0), Number(p[0] || 0) * 110.574]);
    let area = 0;
    for (let i = 0, j = xy.length - 1; i < xy.length; j = i++) area += xy[j][0] * xy[i][1] - xy[i][0] * xy[j][1];
    return Math.abs(area) / 2;
  }
  async function askAiTempFeature(feature) {
    if (tempMeta(feature).enabled === false) return toast('Active esta geometría para evaluarla con IA.', 'wa', 3200);
    const pts = tempPoints(feature);
    const meta = tempMeta(feature);
    const isLine = feature?.geometry?.type === 'LineString';
    const isPoly = feature?.geometry?.type === 'Polygon';
    const km = tempLineKm(pts);
    const sample = pts.filter((_, i) => i === 0 || i === pts.length - 1 || i % Math.max(1, Math.ceil(pts.length / 8)) === 0).slice(0, 10);
    const prompt = isLine
      ? `Evalúa técnicamente esta ruta de archivo geográfico temporal para una línea de ${meta.lineType}, ${meta.voltageKv || 's/d'} kV, ${meta.circuit === 'doble' ? 'doble terna' : 'simple terna'}, longitud aproximada ${f2(km,2)} km. Nombre: ${feature.name || 'Ruta temporal'}. Coordenadas muestra: ${JSON.stringify(sample)}. Recomienda conductor, estructura, riesgos de relieve, servidumbre, cruces, permisos y si conviene ajustar la ruta.`
      : `Evalúa técnicamente este polígono de archivo geográfico temporal de área aproximada ${f2(tempPolygonAreaKm2(pts),3)} km2. Nombre: ${feature.name || 'Polígono temporal'}. Coordenadas muestra: ${JSON.stringify(sample)}. Revisa concesiones vigentes o en trámite, SET cercanas, líneas cruzadas, relieve, viabilidad territorial y recomendaciones para el expediente.`;
    aiPanel?.classList.add('open');
    document.getElementById('dockAiChat')?.classList.add('on');
    addAiMessage(prompt, 'user');
    aiHistory.push({role:'user', content:prompt});
    const wait = addAiMessage(isLine ? 'Evaluando ruta temporal con IA...' : 'Evaluando polígono temporal con IA...', 'bot', 'ai-msg-wait');
    try {
      const payload = {
        messages:aiHistory.slice(-8),
        zone:zoneState.last || null,
        current_polygon:isPoly ? pts.slice(0, 700).map(p => ({lat:Number(p[0]), lng:Number(p[1])})) : aiReferencePolygon(),
        opportunities:(zoneState.opportunityData || []).slice(0, 8),
        viewer:{...collectViewerContext(prompt), archivo_temporal:{nombre:feature.name || '', tipo:feature.geometry?.type || '', longitud_km:isLine ? km : null, area_km2:isPoly ? tempPolygonAreaKm2(pts) : null, especificacion:isLine ? meta : {analysisType:meta.analysisType}}},
      };
      const resp = await fetch('api/openai_chat.php', {method:'POST', headers:csrfHeaders({'Content-Type':'application/json'}), body:JSON.stringify(payload)});
      const data = await readJsonResponse(resp);
      if (!data.ok) throw new Error(data.error || data.msg || data.message || `HTTP ${resp.status}`);
      wait.classList.remove('ai-msg-wait');
      const answer = cleanAiText(data.text || 'No se recibió respuesta.');
      wait.textContent = answer;
      attachAiPdfButton(wait, prompt, answer, data, payload);
      aiHistory.push({role:'assistant', content:answer});
    } catch (err) {
      wait.classList.remove('ai-msg-wait');
      wait.textContent = 'No se pudo evaluar la geometría temporal con IA: ' + err.message;
      toast('IA temporal no disponible: ' + err.message, 'er', 6000);
    }
  }
  document.getElementById('dockTempGeo')?.addEventListener('click', () => {
    if (window.CVCGeoWorkspace?.open) {
      window.CVCGeoWorkspace.open();
      return;
    }
    tempGeoPanel?.classList.toggle('open');
    document.getElementById('dockTempGeo')?.classList.toggle('on', tempGeoPanel?.classList.contains('open'));
  });
  document.getElementById('tempGeoClose')?.addEventListener('click', () => {
    tempGeoPanel?.classList.remove('open');
    document.getElementById('dockTempGeo')?.classList.remove('on');
  });
  document.getElementById('tempGeoClear')?.addEventListener('click', clearTempGeo);
  tempGeoModeEl?.addEventListener('change', () => {
    tempSyncLineDefaultsVisibility();
    if (!tempGeoState.features.length) return;
    tempGeoState.metaById.clear();
    tempGeoState.analysisById.clear();
    tempGeoState.pendingById.clear();
    tempRefreshLoadedFeatures();
  });
  tempSyncLineDefaultsVisibility();
  (function initTempGeoDrag(){
    if (!tempGeoPanel) return;
    const head = tempGeoPanel.querySelector('.temp-geo-head');
    if (!head) return;
    let dragging = false, sx = 0, sy = 0, startLeft = 0, startTop = 0;
    head.addEventListener('pointerdown', e => {
      if (e.target?.closest?.('button,input,select')) return;
      dragging = true;
      const rect = tempGeoPanel.getBoundingClientRect();
      tempGeoPanel.style.left = `${rect.left}px`;
      tempGeoPanel.style.top = `${rect.top}px`;
      tempGeoPanel.style.right = 'auto';
      tempGeoPanel.style.bottom = 'auto';
      sx = e.clientX; sy = e.clientY; startLeft = rect.left; startTop = rect.top;
      head.setPointerCapture?.(e.pointerId);
    });
    head.addEventListener('pointermove', e => {
      if (!dragging) return;
      const rect = tempGeoPanel.getBoundingClientRect();
      const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
      const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
      tempGeoPanel.style.left = `${Math.max(8, Math.min(maxLeft, startLeft + e.clientX - sx))}px`;
      tempGeoPanel.style.top = `${Math.max(8, Math.min(maxTop, startTop + e.clientY - sy))}px`;
    });
    head.addEventListener('pointerup', e => {
      dragging = false;
      head.releasePointerCapture?.(e.pointerId);
    });
  })();
  tempGeoForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const file = tempGeoFile?.files?.[0];
    if (!file) return toast('Seleccione un KML, KMZ, GeoJSON o JSON.', 'wa');
    const fd = new FormData();
    if (APP_CSRF) fd.append('_csrf', APP_CSRF);
    fd.append('kml_file', file);
    toast('Leyendo archivo geográfico temporal...', 'in', 3200);
    try {
      const resp = await fetch('api/temp_kml.php', {method:'POST', body:fd});
      const data = await readJsonResponse(resp);
      if (!data.ok) throw new Error(data.message || data.msg || `HTTP ${resp.status}`);
      const features = data.features || [];
      tempGeoState.notice = data.truncated
        ? `Archivo grande: se muestran ${features.length.toLocaleString('es-PE')} geometrías de ${Number(data.total_features ?? data.total_placemarks ?? 0).toLocaleString('es-PE')} elementos. Divida el archivo si necesita evaluar los restantes.`
        : '';
      drawTempFeatures(features);
      const lines = features.filter(f => f.geometry?.type === 'LineString').length;
      const polys = features.filter(f => f.geometry?.type === 'Polygon').length;
      toast(
        data.saved
          ? `Archivo cargado y guardado en ${data.saved_path}: ${features.length} geometrías visibles.`
          : data.truncated
          ? `Archivo cargado con límite seguro: ${features.length} geometrías visibles.`
          : `Archivo temporal cargado: ${features.length} geometrías (${polys} polígonos, ${lines} líneas)`,
        data.truncated && !data.saved ? 'wa' : 'ok',
        data.truncated || data.saved ? 7000 : 4200
      );
    } catch (err) {
      toast('No se pudo leer el archivo geográfico: ' + err.message, 'er', 6500);
    }
  });
  function syncTempFeatureControl(target, redraw=false) {
    const id = target?.dataset?.tempEnabled || target?.dataset?.tempLineType || target?.dataset?.tempVoltage || target?.dataset?.tempCircuit;
    if (!id) return false;
    const {feature, idx} = tempFeatureById(id);
    if (!feature) return false;
    const meta = tempEnsureMeta(feature, idx);
    if (target.matches?.('[data-temp-enabled]')) meta.enabled = !!target.checked;
    if (target.matches?.('[data-temp-line-type]')) meta.lineType = target.value === 'transmision' ? 'transmision' : 'distribucion';
    if (target.matches?.('[data-temp-voltage]')) meta.voltageKv = Number(target.value || 0);
    if (target.matches?.('[data-temp-circuit]')) meta.circuit = target.value === 'doble' ? 'doble' : 'simple';
    tempGeoState.analysisById.delete(id);
    tempGeoState.pendingById.delete(id);
    if (redraw) tempRefreshLoadedFeatures();
    return true;
  }
  document.addEventListener('input', e => {
    if (e.target?.matches?.('[data-temp-voltage]')) syncTempFeatureControl(e.target, false);
  });
  document.addEventListener('change', e => {
    if (syncTempFeatureControl(e.target, true)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
  document.addEventListener('click', e => {
    const btn = e.target?.closest?.('[data-temp-zoom],[data-temp-eval],[data-temp-line],[data-temp-ai],[data-temp-full],[data-temp-report]');
    if (!btn) return;
    const id = btn.dataset.tempAi || btn.dataset.tempFull || btn.dataset.tempReportId;
    const idx = id ? tempGeoState.features.findIndex(f => f.id === id) : Number(btn.dataset.tempZoom ?? btn.dataset.tempEval ?? btn.dataset.tempLine);
    const feature = tempGeoState.features[idx];
    if (!feature) return;
    e.preventDefault();
    e.stopPropagation();
    const bounds = tempBounds([feature]);
    if (btn.matches('[data-temp-zoom]') && bounds?.isValid?.()) {
      mapInstance.fitBounds(bounds, {padding:[24,24], maxZoom:15});
      return;
    }
    if (btn.matches('[data-temp-ai]')) {
      askAiTempFeature(feature);
      return;
    }
    if (btn.matches('[data-temp-report]')) {
      downloadTempReport(feature, btn.dataset.tempReport).catch(err => toast('No se pudo generar informe temporal: ' + err.message, 'er', 6500));
      return;
    }
    if (btn.matches('[data-temp-full]')) {
      analyzeTempOnServer(feature).catch(err => toast('No se pudo analizar temporal: ' + err.message, 'er', 6500));
      return;
    }
    evaluateTempFeature(idx);
  });

  function selectTempFeature(id, fit=true) {
    const {feature, idx} = tempFeatureById(id);
    if (!feature) return null;
    tempGeoState.selected = id;
    const layer = tempGeoState.layerById.get(id);
    if (fit) {
      if (layer?.getBounds?.()?.isValid?.()) mapInstance.fitBounds(layer.getBounds(), {padding:[48,48], maxZoom:16});
      else if (layer?.getLatLng) mapInstance.setView(layer.getLatLng(), Math.max(15, mapInstance.getZoom()));
    }
    document.dispatchEvent(new CustomEvent('cvc:geo-feature-selected', {detail:{id, feature, index:idx}}));
    return feature;
  }

  window.CVC_GEO_BRIDGE = {
    map:mapInstance,
    state:tempGeoState,
    zoneState,
    csrf:APP_CSRF,
    toast,
    readJsonResponse,
    drawFeatures:(features, options={}) => drawTempFeatures(features, options.resetMeta !== false, options.fit !== false),
    appendFeatures:(features, fit=true) => drawTempFeatures([...(tempGeoState.features || []), ...(Array.isArray(features) ? features : [])], false, fit),
    clear:clearTempGeo,
    select:selectTempFeature,
    refresh:tempRefreshLoadedFeatures,
    featureById:id => tempFeatureById(id).feature,
    featureMeta:feature => ({...tempMeta(feature)}),
    setFeatureMeta:(feature, values={}) => {
      const meta = tempMeta(feature);
      Object.assign(meta, values || {});
      tempRefreshLoadedFeatures();
      return {...meta};
    },
    setAllEnabled:enabled => {
      for (const feature of tempGeoState.features || []) tempMeta(feature).enabled = !!enabled;
      tempRefreshLoadedFeatures();
    },
    setVisibility:(ids, visible) => {
      const wanted = new Set(Array.isArray(ids) ? ids.map(String) : [String(ids || '')]);
      for (const feature of tempGeoState.features || []) {
        if (wanted.has(String(feature?.id || ''))) tempMeta(feature).visible = !!visible;
      }
      tempRefreshLoadedFeatures();
    },
    setAllVisible:visible => {
      for (const feature of tempGeoState.features || []) tempMeta(feature).visible = !!visible;
      tempRefreshLoadedFeatures();
    },
    analyze:feature => analyzeTempOnServer(feature),
    askAi:feature => askAiTempFeature(feature),
    report:(feature, format='pdf') => downloadTempReport(feature, format),
    points:tempPoints,
    lengthKm:tempLineKm,
    areaKm2:tempPolygonAreaKm2,
    viewerContext:collectViewerContext,
    resolveEntities:resolveAiEntities,
    importProject:(project={}) => {
      tempGeoState.metaById.clear();
      for (const [id, meta] of Object.entries(project.meta || {})) tempGeoState.metaById.set(id, {...meta});
      tempGeoState.notice = project.notice || '';
      drawTempFeatures(Array.isArray(project.features) ? project.features : [], false, false);
      const view = project.view;
      if (view?.center && Number.isFinite(Number(view.zoom))) mapInstance.setView(view.center, Number(view.zoom));
    },
    snapshot:() => ({
      features:JSON.parse(JSON.stringify(tempGeoState.features || [])),
      meta:Object.fromEntries([...tempGeoState.metaById.entries()].map(([id, meta]) => [id, {...meta}])),
      notice:tempGeoState.notice || '',
      view:{center:mapInstance.getCenter(), zoom:mapInstance.getZoom()},
    }),
  };
  document.dispatchEvent(new CustomEvent('cvc:map-ready', {detail:{bridge:window.CVC_GEO_BRIDGE}}));

  const aiPanel = document.getElementById('aiChatPanel');
  const aiMessages = document.getElementById('aiChatMessages');
  const aiInput = document.getElementById('aiChatInput');
  const aiForm = document.getElementById('aiChatForm');
  const aiHistory = [];
  const aiWelcome = 'Pregúntame por zonas viables para concesión o convenio. Puedo contrastar SET, concesiones, cultivos, acuicultura y media tensión.';
  const aiScout = { running:false, lastKey:'', timer:null };
  function aiReferencePolygon() {
    if (zoneState.points.length >= 3) {
      return zoneState.points.map(p => ({lat:Number(p.lat), lng:Number(p.lng)}));
    }
    const feature = (tempGeoState.features || []).find(f =>
      f?.geometry?.type === 'Polygon' && tempMeta(f).enabled !== false && tempPoints(f).length >= 3
    );
    if (!feature) return [];
    return tempPoints(feature).slice(0, 700).map(p => ({lat:Number(p[0]), lng:Number(p[1])}));
  }
  function aiTempGeometryContext() {
    return (tempGeoState.features || []).filter(f => tempMeta(f).enabled !== false).slice(0, 12).map(f => {
      const pts = tempPoints(f);
      const step = Math.max(1, Math.ceil(pts.length / 10));
      return {
        nombre:f.name || 'Geometria temporal',
        tipo:f?.geometry?.type || '',
        puntos:pts.length,
        area_km2:f?.geometry?.type === 'Polygon' ? tempPolygonAreaKm2(pts) : null,
        longitud_km:f?.geometry?.type === 'LineString' ? tempLineKm(pts) : null,
        coordenadas_muestra:pts.filter((_, i) => i === 0 || i === pts.length - 1 || i % step === 0).slice(0, 12),
        archivo:f.properties?.source_file || '',
      };
    });
  }
  function resolveAiEntities(query, limit=8) {
    const normalized = normSearchText(query || '');
    if (!normalized || normalized.length < 3) return [];
    const tokens = normalized.split(' ').filter(word => word.length >= 3);
    const ranked = [];
    for (const hit of SIG.featureIndex || []) {
      const title = normSearchText(hit.title || '');
      if (!title) continue;
      const titleCore = title.replace(/^(set|subestacion|linea|lt|zona|concesion)\s+/, '').trim();
      let score = 0;
      if (normalized.includes(title)) score += 160 + Math.min(40, title.length);
      if (titleCore.length >= 5 && normalized.includes(titleCore)) score += 135 + Math.min(35, titleCore.length);
      const titleWords = title.split(' ').filter(word => word.length >= 3);
      const matches = titleWords.filter(word => tokens.includes(word)).length;
      if (matches >= 2) score += matches * 18 + Math.round(40 * matches / Math.max(1, titleWords.length));
      if (matches === 1 && titleWords.length === 1) score += 32;
      if (score < 48) continue;
      let bounds = null;
      try {
        const b = hit.layer?.getBounds?.();
        if (b?.isValid?.()) bounds = {norte:b.getNorth(), sur:b.getSouth(), este:b.getEast(), oeste:b.getWest()};
      } catch (_) {}
      const latlng = hit.latlng ? {lat:Number(hit.latlng.lat), lng:Number(hit.latlng.lng)} : null;
      const properties = {};
      for (const [key, value] of Object.entries(hit.properties || {})) {
        if (value == null || typeof value === 'object' || String(key).startsWith('_')) continue;
        const text = String(value).trim();
        if (!text) continue;
        properties[key] = text.slice(0, 180);
        if (Object.keys(properties).length >= 16) break;
      }
      ranked.push({
        score,
        nombre:hit.title || '',
        tipo:hit.label || hit.kind || '',
        fuente:hit.source || '',
        centro:latlng,
        bounds,
        propiedades:properties,
      });
    }
    ranked.sort((a,b) => b.score - a.score || b.nombre.length - a.nombre.length);
    const unique = [];
    const seen = new Set();
    for (const item of ranked) {
      const key = normSearchText(`${item.tipo}|${item.nombre}|${item.centro?.lat || ''}|${item.centro?.lng || ''}`);
      if (seen.has(key)) continue;
      seen.add(key);
      unique.push(item);
      if (unique.length >= limit) break;
    }
    return unique;
  }
  function collectViewerContext(query='') {
    const activeLayers = [...document.querySelectorAll('.lyr.on[data-id]')].map(el => ({
      id:el.dataset.id || '',
      nombre:el.querySelector('.lyr-name')?.textContent?.trim() || el.dataset.id || '',
      grupo:el.closest('.sb-layer-group')?.querySelector('.sb-sub-t')?.textContent?.trim() || '',
      tipo:el.dataset.tipo || '',
      proveedor:el.dataset.provider || 'archivo',
    })).slice(0, 60);
    const b = mapInstance?.getBounds?.();
    const referencePolygon = aiReferencePolygon();
    return {
      capas_activas:activeLayers,
      total_capas_activas:activeLayers.length,
      vista_mapa:b ? {
        zoom:mapInstance.getZoom(),
        centro:mapInstance.getCenter(),
        bounds:{
          norte:b.getNorth(), sur:b.getSouth(),
          este:b.getEast(), oeste:b.getWest(),
        },
      } : null,
      capas_indexadas:SIG.featureIndex.length,
      tiene_poligono_actual:referencePolygon.length >= 3,
      origen_poligono_actual:zoneState.points.length >= 3 ? 'zona dibujada o evaluada' : (referencePolygon.length >= 3 ? 'KML/KMZ temporal' : ''),
      geometrias_kml_temporales:aiTempGeometryContext(),
      entidades_mencionadas:resolveAiEntities(query, 8),
    };
  }
  function focusAiEntity(entity) {
    if (!entity) return false;
    const lat = Number(entity.lat ?? entity.centro?.lat);
    const lng = Number(entity.lng ?? entity.centro?.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
    const wantedName = normSearchText(entity.nombre || '');
    const wantedType = normSearchText(entity.tipo || '');
    const ranked = (SIG.featureIndex || []).map(hit => {
      const title = normSearchText(hit.title || '');
      const label = normSearchText(hit.label || hit.kind || '');
      const hitLat = Number(hit.latlng?.lat);
      const hitLng = Number(hit.latlng?.lng);
      let score = 0;
      if (wantedName && title === wantedName) score += 220;
      else if (wantedName && (title.includes(wantedName) || wantedName.includes(title))) score += 150;
      if (wantedType && (label.includes(wantedType) || wantedType.includes(label))) score += 25;
      if (Number.isFinite(hitLat) && Number.isFinite(hitLng)) {
        const distance = Math.hypot(hitLat - lat, hitLng - lng);
        if (distance < .0002) score += 80;
        else if (distance < .01) score += 30;
      }
      return {hit, score};
    }).filter(item => item.score > 0).sort((a,b) => b.score - a.score);
    if (ranked[0]?.hit) {
      focusSearchHit(ranked[0].hit);
      return true;
    }
    mapInstance.flyTo([lat, lng], 14, {duration:1.1});
    const marker = L.circleMarker([lat, lng], {
      radius:11, color:'#f59e0b', weight:4, fillColor:'#fde68a', fillOpacity:.6,
      interactive:false,
    }).addTo(mapInstance);
    marker.bindTooltip(entity.nombre || 'Entidad localizada', {permanent:false, direction:'top'}).openTooltip();
    setTimeout(() => marker.remove(), 9000);
    return true;
  }
  async function runOpportunityScout(showFirst=false) {
    if (aiScout.running) return;
    aiScout.running = true;
    try {
      const resp = await fetch('api/zone_analysis.php?mode=opportunities&use_ai=0');
      const data = await readJsonResponse(resp);
      if (data.status === 'processing') return;
      const candidates = Array.isArray(data.candidatos) ? data.candidatos : [];
      if (!candidates.length) return;
      zoneState.opportunityData = candidates;
      const top = candidates[0];
      const key = `${top.nombre || ''}|${top.lat || ''}|${top.lng || ''}|${top.score || ''}`;
      if (showFirst || key !== aiScout.lastKey) {
        aiScout.lastKey = key;
        const set = top.set_referencia || {};
        toast(`IA territorial: ${top.viabilidad || 'Media'} ${f2(top.score,0)}/100 en ${top.nombre || 'zona sugerida'}; SET ${set.nombre || 'cercana'} con ${f2(set.disponible_total_mva,1)} MVA libres.`, top.score >= 72 ? 'ok' : 'in', 9000);
      }
    } catch (err) {
      if (showFirst) toast('Monitor IA territorial pendiente: ' + err.message, 'wa', 5000);
    } finally {
      aiScout.running = false;
    }
  }
  function startOpportunityScout() {
    if (aiScout.timer) return;
    setTimeout(() => runOpportunityScout(true), 12000);
    aiScout.timer = setInterval(() => runOpportunityScout(false), 6 * 60 * 1000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) runOpportunityScout(false);
    });
  }
  function cleanAiText(text) {
    return String(text || '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/__([^_]+)__/g, '$1')
      .replace(/`{1,3}/g, '')
      .replace(/^\s{0,3}#{1,6}\s*/gm, '')
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1 ($2)')
      .replace(/[ \t]+\n/g, '\n')
      .trim();
  }
  function addAiMessage(text, role='bot', extra='') {
    if (!aiMessages) return null;
    const el = document.createElement('div');
    el.className = `ai-msg ai-msg-${role} ${extra}`.trim();
    el.textContent = cleanAiText(text);
    aiMessages.appendChild(el);
    aiMessages.scrollTop = aiMessages.scrollHeight;
    return el;
  }
  function aiWantsPdf(text) {
    return /\b(pdf|informe|descargar|exportar|documento)\b/i.test(String(text || ''));
  }
  async function downloadAiPdf(question, answer, data={}, payload={}) {
    const body = {
      title:'Informe del Asistente IA territorial',
      question,
      answer,
      opportunities:Array.isArray(data.polygons) && data.polygons.length ? data.polygons : (payload.opportunities || []),
      viewer:payload.viewer || collectViewerContext(),
      web:data.web || {},
      sources:data.sources || [],
    };
    const resp = await fetch('api/chat_report.php', {
      method:'POST',
      headers:csrfHeaders({'Content-Type':'application/json'}),
      body:JSON.stringify(body),
    });
    const type = resp.headers.get('Content-Type') || '';
    if (!resp.ok || !type.includes('application/pdf')) {
      const msg = await resp.text().catch(() => '');
      throw new Error(msg || `HTTP ${resp.status}`);
    }
    const blob = await resp.blob();
    saveBlob(blob, `informe_chat_ia_${new Date().toISOString().slice(0,10)}.pdf`);
  }
  function attachAiPdfButton(el, question, answer, data={}, payload={}) {
    if (!el || el.querySelector('.ai-pdf-btn')) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ai-pdf-btn';
    btn.textContent = 'PDF';
    btn.addEventListener('click', async ev => {
      ev.stopPropagation();
      btn.disabled = true;
      const prev = btn.textContent;
      btn.textContent = 'Generando...';
      try {
        await downloadAiPdf(question, answer, data, payload);
        toast('PDF del chat descargado', 'ok', 2600);
      } catch (err) {
        toast('No se pudo generar PDF: ' + err.message, 'er', 6500);
      } finally {
        btn.disabled = false;
        btn.textContent = prev;
      }
    });
    el.appendChild(btn);
  }
  document.getElementById('dockAiChat')?.addEventListener('click', () => {
    aiPanel?.classList.toggle('open');
    document.getElementById('dockAiChat')?.classList.toggle('on', aiPanel?.classList.contains('open'));
    if (aiPanel?.classList.contains('open')) setTimeout(() => aiInput?.focus(), 80);
  });
  document.getElementById('aiChatClose')?.addEventListener('click', () => {
    aiPanel?.classList.remove('open');
    document.getElementById('dockAiChat')?.classList.remove('on');
  });
  document.getElementById('aiChatMin')?.addEventListener('click', e => {
    e.stopPropagation();
    aiPanel?.classList.toggle('minimized');
  });
  document.getElementById('aiChatNew')?.addEventListener('click', e => {
    e.stopPropagation();
    aiHistory.length = 0;
    if (aiMessages) {
      aiMessages.innerHTML = '';
      addAiMessage(aiWelcome, 'bot');
    }
    aiPanel?.classList.remove('minimized');
    aiInput?.focus();
  });
  (function initAiDrag(){
    if (!aiPanel) return;
    const head = aiPanel.querySelector('.ai-chat-head');
    if (!head) return;
    let dragging = false, sx = 0, sy = 0, startLeft = 0, startTop = 0;
    head.addEventListener('pointerdown', e => {
      if (e.target?.closest?.('button')) return;
      dragging = true;
      const rect = aiPanel.getBoundingClientRect();
      aiPanel.style.left = `${rect.left}px`;
      aiPanel.style.top = `${rect.top}px`;
      aiPanel.style.right = 'auto';
      aiPanel.style.bottom = 'auto';
      sx = e.clientX; sy = e.clientY; startLeft = rect.left; startTop = rect.top;
      head.setPointerCapture?.(e.pointerId);
    });
    head.addEventListener('pointermove', e => {
      if (!dragging) return;
      const rect = aiPanel.getBoundingClientRect();
      const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
      const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
      aiPanel.style.left = `${Math.max(8, Math.min(maxLeft, startLeft + e.clientX - sx))}px`;
      aiPanel.style.top = `${Math.max(8, Math.min(maxTop, startTop + e.clientY - sy))}px`;
    });
    head.addEventListener('pointerup', e => {
      dragging = false;
      head.releasePointerCapture?.(e.pointerId);
    });
  })();
  aiForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const text = aiInput?.value.trim();
    if (!text) return;
    aiInput.value = '';
    addAiMessage(text, 'user');
    aiHistory.push({role:'user', content:text});
    const wait = addAiMessage('Analizando capas, disponibilidad y zonas productivas...', 'bot', 'ai-msg-wait');
    const submit = aiForm.querySelector('button');
    submit.disabled = true;
    try {
      const payload = {
        messages: aiHistory.slice(-8),
        zone: zoneState.last || null,
        current_polygon: aiReferencePolygon(),
        opportunities: (zoneState.opportunityData || []).slice(0, 8),
        viewer: collectViewerContext(text),
      };
      const resp = await fetch('api/openai_chat.php', {
        method:'POST',
        headers:csrfHeaders({'Content-Type':'application/json'}),
        body:JSON.stringify(payload),
      });
      const data = await readJsonResponse(resp);
      if (!data.ok) {
        const detail = data.error || data.msg || data.message || `HTTP ${resp.status}`;
        throw new Error(data.http_code ? `${detail} (HTTP ${data.http_code})` : detail);
      }
      wait.classList.remove('ai-msg-wait');
      const answerText = cleanAiText(data.text || 'No se recibio respuesta.');
      wait.textContent = answerText;
      attachAiPdfButton(wait, text, answerText, data, payload);
      aiHistory.push({role:'assistant', content:answerText});
      if (data.focus_entity && focusAiEntity(data.focus_entity)) {
        toast(`${data.focus_entity.nombre || 'Entidad'} localizada en el mapa`, 'ok', 3600);
      }
      if (Array.isArray(data.polygons) && data.polygons.length) {
        const drawn = drawOpportunityCandidates(data.polygons, true);
        toast(`IA dibujo ${drawn} poligonos sugeridos`, drawn ? 'ok' : 'wa', 4200);
      }
      if (data.pdf_requested || aiWantsPdf(text)) {
        try {
          await downloadAiPdf(text, answerText, data, payload);
          toast('PDF del chat descargado', 'ok', 2800);
        } catch (pdfErr) {
          toast('Respuesta lista, pero el PDF fallo: ' + pdfErr.message, 'er', 6500);
        }
      }
    } catch (err) {
      wait.classList.remove('ai-msg-wait');
      wait.textContent = 'No se pudo consultar OpenAI: ' + err.message;
      toast('Chat IA no disponible: ' + err.message, 'er', 5500);
    } finally {
      submit.disabled = false;
    }
  });

  mapInstance.on('mousedown', e => {
    if (!zoneState.active) return;
    zoneState.drawing = true;
    zoneState.points = [e.latlng];
    if (zoneState.draft) zoneState.layer.removeLayer(zoneState.draft);
    zoneState.draft = L.polyline([e.latlng], {color:'#0f766e', weight:2.5, dashArray:'5,6'}).addTo(zoneState.layer);
    L.DomEvent.preventDefault(e.originalEvent);
  });
  mapInstance.on('mousemove', e => {
    if (!zoneState.active || !zoneState.drawing) return;
    const last = zoneState.points[zoneState.points.length - 1];
    if (last && last.distanceTo(e.latlng) < 18) return;
    zoneState.points.push(e.latlng);
    zoneState.draft.setLatLngs(zoneState.points);
  });
  mapInstance.on('mouseup', () => {
    if (!zoneState.active || !zoneState.drawing) return;
    finishZoneDraw();
  });
  mapInstance.on('contextmenu', e => {
    if (!zoneState.active) return;
    L.DomEvent.preventDefault(e.originalEvent);
    clearZoneDraft();
    setZoneDrawMode(false);
    toast('Dibujo cancelado', 'wa', 2000);
  });

  /* Ajuste de tamaño: varios invalidateSize para estabilizar Leaflet. */
  [100,300,600,1000,2000].forEach(d => setTimeout(() => mapInstance.invalidateSize(), d));
  if (window.ResizeObserver) {
    new ResizeObserver(() => mapInstance.invalidateSize()).observe(mapDiv);
  }
  window.addEventListener('resize', () => mapInstance.invalidateSize());

  /* ?"??"? Marcadores demo desactivados: el mapa usa capas oficiales y cargadas ?"??"? */
  const SHOW_DEMO_SUBSTATIONS = false;
  mkIcon.__cache = {};
  function mkIcon(color, sel=false) {
    const sz = sel ? 40 : 30;
    return L.divIcon({
      className:'', iconSize:[sz,sz], iconAnchor:[sz/2,sz/2],
      html:`<div style="width:${sz}px;height:${sz}px;border-radius:50%;
        background:radial-gradient(circle at 36% 34%,rgba(255,255,255,.85) 0%,${color} 44%,${color}66 100%);
        border:${sel?3:2}px solid ${color};
        box-shadow:0 0 ${sel?22:9}px ${color},0 0 ${sel?44:20}px ${color}44;
        display:flex;align-items:center;justify-content:center;font-size:${Math.round(sz*.42)}px"></div>`,
    });
  }
  const colorSE = se => analizarSE(se).disp > 0 ? '#10b981' : '#ef4444';

  const mkrById = {};

  if (SHOW_DEMO_SUBSTATIONS && Array.isArray(_SUBS)) {
    const seGroup = L.layerGroup().addTo(mapInstance);
    _SUBS.forEach(se => {
      const color = colorSE(se);
      const mkr   = L.marker([se.lat,se.lng], {icon:mkIcon(color), title:se.nombre}).addTo(seGroup);
      mkr.bindTooltip(`
        <div style="font-family:'Inter',sans-serif;line-height:1.8">
          <b style="font-size:12px;color:#e2f0ff">${se.nombre}</b><br>
          <span style="font-size:10px;color:#5a7fb5">${se.empresa}</span><br>
          <span style="color:#f59e0b;font-size:11px">${se.tension} · ${se.potencia} MVA</span><br>
          <span style="font-size:9px;color:#2a4060">Ubicacion: ${se.region} &middot; ${se.provincia}</span>
        </div>`, {className:'se-tip', direction:'top', offset:[0,-14]});
      mkr.on('click', () => openPopup(se, mkr));
      mkrById[se.id] = {mkr, se};
    });
  }

  /* ?"??"? Popup ?"??"? */
  let curPop = null;
  function openPopup(se, mkr) {
    if (SIG.activeMkr && SIG.activeMkr !== mkr) {
      const prev = Object.values(mkrById).find(x=>x.mkr===SIG.activeMkr)?.se;
      if (prev) SIG.activeMkr.setIcon(mkIcon(colorSE(prev), false));
    }
    mkr.setIcon(mkIcon('#f59e0b', true));
    SIG.activeMkr = mkr;
    mapInstance.panTo([se.lat,se.lng], {animate:true,duration:.5});
    if (curPop) mapInstance.closePopup(curPop);
    curPop = L.popup({maxWidth:340,minWidth:300,autoPan:true,closeOnEscapeKey:true})
      .setLatLng([se.lat,se.lng])
      .setContent(buildPopup(se))
      .openOn(mapInstance);
    setTimeout(() => {
      const b = document.getElementById(`ab-${se.id}`);
      if (b) b.addEventListener('click', () => runAnalysis(se));
    }, 60);
  }

  function buildPopup(se) {
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">Subestación</span>
        <div class="pu-name">${se.nombre}</div>
        <div class="pu-emp">${se.empresa}</div>
        <div class="pu-tags">
          <span class="ptag pt-y">${se.tension}</span>
          <span class="ptag pt-b">${se.potencia} MVA</span>
          <span class="ptag pt-g">fp ${se.fp}</span>
        </div>
      </div>
      <div class="pu-body">
        <div class="attr-row"><span class="attr-k">Región</span><span class="attr-v">${se.region}</span></div>
        <div class="attr-row"><span class="attr-k">Provincia</span><span class="attr-v">${se.provincia}</span></div>
        <div class="attr-row"><span class="attr-k">Coordenadas</span><span class="attr-v">${se.lat.toFixed(4)}, ${se.lng.toFixed(4)}</span></div>
        <div class="attr-row"><span class="attr-k">Tensión</span><span class="attr-v">${se.tension}</span></div>
        <div class="attr-row"><span class="attr-k">Pot. nominal</span><span class="attr-v">${se.potencia} MVA</span></div>
        <button class="btn-analyze" id="ab-${se.id}">Analizar disponibilidad de carga</button>
        <div id="ar-${se.id}"></div>
      </div>
    </div>`;
  }

  /* ?"??"? An?lisis ?"??"? */
  function runAnalysis(se) {
    const btn = document.getElementById(`ab-${se.id}`);
    const res = document.getElementById(`ar-${se.id}`);
    btn.disabled = true;
    btn.innerHTML = `<span style="display:flex;align-items:center;gap:8px;justify-content:center">
      <span style="width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.3);
      border-top-color:#fff;animation:spin .7s linear infinite;display:inline-block"></span>
      Calculando…</span>`;

    setTimeout(() => {
      const r    = analizarSE(se);
      const ok   = r.disp > 0;
      const pct  = Math.min(100, Math.max(0, r.ut));
      const bCls = pct>90?'dn':pct>75?'wn':'ok';
      const maxKW = Math.max(...r.filas.map(f=>f.kW));

      const bars = r.filas.map((f,i) => `
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px">
          <div style="width:100%;border-radius:2px 2px 0 0;
            height:${((f.kW/maxKW)*44+2).toFixed(0)}px;
            background:${i===r.peakIdx
              ? 'linear-gradient(180deg,#f59e0b,rgba(245,158,11,.3))'
              : 'linear-gradient(180deg,#00d4ff,rgba(0,212,255,.25))'};"
            title="${f.mes}: ${f2(f.mva,3)} MVA"></div>
          <div style="font-size:7px;color:#2a4060;font-family:monospace">${f.mesC}</div>
        </div>`).join('');

      const rows = r.filas.map((f,i) => `
        <tr style="${i===r.peakIdx?'background:rgba(245,158,11,.06)':''}">
          <td style="padding:3px 6px;font-size:10px;${i===r.peakIdx?'color:#f59e0b;font-weight:700':''}">
            ${i===r.peakIdx?'Pico · ':''}${f.mes}</td>
          <td style="padding:3px 6px;text-align:right;font-size:10px">${fi(f.energia)}</td>
          <td style="padding:3px 6px;text-align:right;font-size:10px">${f2(f.kW,1)}</td>
          <td style="padding:3px 6px;text-align:right;font-size:10px">${f2(f.mva,3)}</td>
        </tr>`).join('');

      res.innerHTML = `
      <div class="ana-res">
        <div class="ana-ttl">Resultado estimado</div>
        <div class="ana-kpis">
          <div class="a-kpi c-acc"><div class="a-kpi-l">Pot. Nominal</div>
            <div class="a-kpi-v">${f2(se.potencia,0)}</div><div class="a-kpi-u">MVA</div></div>
          <div class="a-kpi ${ok?'c-g':'c-r'}"><div class="a-kpi-l">Disponib.</div>
            <div class="a-kpi-v">${f2(r.disp,1)}</div><div class="a-kpi-u">MVA</div></div>
          <div class="a-kpi c-${r.ut>90?'r':r.ut>75?'a':'g'}"><div class="a-kpi-l">Utilización</div>
            <div class="a-kpi-v">${f2(r.ut,1)}</div><div class="a-kpi-u">%</div></div>
          <div class="a-kpi c-acc"><div class="a-kpi-l">Máx. Anual</div>
            <div class="a-kpi-v">${f2(r.maxMVA,1)}</div><div class="a-kpi-u">MVA</div></div>
          <div class="a-kpi c-a"><div class="a-kpi-l">Mes pico</div>
            <div class="a-kpi-v">${MESES_C[r.peakIdx]}</div><div class="a-kpi-u">${MESES[r.peakIdx]}</div></div>
          <div class="a-kpi"><div class="a-kpi-l">Factor P.</div>
            <div class="a-kpi-v" style="color:var(--text)">${f2(se.fp,2)}</div><div class="a-kpi-u">fp</div></div>
        </div>
        <div class="util-w">
          <div class="util-lb">
            <span>Utilización del transformador</span>
            <span style="color:${r.ut>90?'var(--red)':r.ut>75?'var(--amber)':'var(--green)'}">${f2(r.ut,1)}%</span>
          </div>
          <div class="util-tr"><div class="util-fi ${bCls}" style="width:${pct.toFixed(1)}%"></div></div>
        </div>
        <div class="mini-ch">
          <div class="mini-ch-t">Potencia mensual (MVA) · pico en ámbar</div>
          <div class="mini-bars">${bars}</div>
        </div>
        <div style="border:1px solid rgba(255,255,255,.05);border-radius:8px;overflow:hidden;margin-bottom:9px">
          <div style="padding:5px 8px;font-size:8.5px;font-weight:700;color:var(--dim);
               text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid rgba(255,255,255,.05)">
            📋 Perfil mensual de carga</div>
          <table style="width:100%;border-collapse:collapse">
            <thead><tr style="border-bottom:1px solid rgba(255,255,255,.05)">
              <th style="padding:4px 6px;text-align:left;font-size:8px;color:var(--dim)">Mes</th>
              <th style="padding:4px 6px;text-align:right;font-size:8px;color:var(--dim)">kWh</th>
              <th style="padding:4px 6px;text-align:right;font-size:8px;color:var(--dim)">kW</th>
              <th style="padding:4px 6px;text-align:right;font-size:8px;color:var(--dim)">MVA</th>
            </tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <div class="pu-st ${ok?'st-ok':'st-no'}">
          <span class="blink">\u25cf</span>
          ${ok?`DISPONIBLE · ${f2(r.disp,2)} MVA libres`:'SIN DISPONIBILIDAD · Transformador saturado'}
        </div>
      </div>`;

      /* KPIs globales */
      SIG.analizadas++;
      SIG.dispTotal += Math.max(0, r.disp);
      ['kpiAna','gAna'].forEach(id => { const e=document.getElementById(id); if(e) e.textContent=SIG.analizadas; });
      ['kpiDisp','gDisp'].forEach(id => { const e=document.getElementById(id); if(e) e.textContent=SIG.dispTotal>0?f2(SIG.dispTotal,1):'—'; });

      btn.disabled  = false;
      btn.innerHTML = '🔄 &nbsp;Recalcular';
      toast(`Análisis: ${se.nombre}`, 'ok');
    }, 850);
  }

  /* ?"??"? Capas GeoJSON locales y OSINERGMIN oficial ?"??"? */
  const CERAUNICO_REST = typeof _URL_CERAUNICO_REST !== 'undefined' ? _URL_CERAUNICO_REST : 'https://gisem.osinergmin.gob.pe/serverosih/rest/services/Electricidad/DescargasAtmosfericas/MapServer';
  const CERAUNICO_YEAR_LAYERS = {2025:5,2024:8,2023:11,2022:14,2021:17,2020:20,2019:23,2018:26,2017:29,2016:32,2015:35,2014:38,2013:41};
  const CERAUNICO_GRID_LAYER = 0;
  const CERAUNICO_BOUNDARY_LAYER = 1;
  const CERAUNICO_CLASSES = [
    {label:'0', color:'#ffffff', risk:'Sin registro'},
    {label:'1', color:'#38c8f4', risk:'Muy bajo'},
    {label:'2', color:'#4f8f3a', risk:'Bajo'},
    {label:'3', color:'#5ee044', risk:'Bajo'},
    {label:'4', color:'#f0e83a', risk:'Moderado'},
    {label:'5 - 8', color:'#f5b43b', risk:'Medio'},
    {label:'9 - 16', color:'#ef3b2d', risk:'Alto'},
    {label:'17 - 32', color:'#d9368b', risk:'Muy alto'},
    {label:'33 - 56', color:'#a855f7', risk:'Muy alto'},
    {label:'57 - 64', color:'#7c3aed', risk:'Critico'},
    {label:'65 - 128', color:'#581c87', risk:'Extremo'},
  ];
  const OSI_LAYERS = {
    'osinergmin-generacion': { layer:'generacion', nombre:'Centrales de generacion', tipo:'point', color:'#ef4444', kind:'generation' },
    'osinergmin-ceraunico': { layer:'ceraunico', nombre:'CERAUNICO descargas atmosfericas', tipo:'raster', color:'#7c3aed', kind:'ceraunico', year:2025 },
    'osinergmin-set-op': { layer:18, nombre:'SET en operación', tipo:'point', color:'#0ea5e9', kind:'set', metric:'set' },
    'osinergmin-lt-op':  { layer:15, nombre:'LT en operación',  tipo:'line',  color:'#2563eb', kind:'line', metric:'lt' },
    'osinergmin-set-pr': { layer:19, nombre:'SET proyectadas',  tipo:'point', color:'#f59e0b', kind:'set' },
    'osinergmin-lt-pr':  { layer:16, nombre:'LT proyectadas',   tipo:'line',  color:'#ec4899', kind:'line' },
    'osinergmin-mt-salida': { layer:21, nombre:'Salidas MT desde SET', tipo:'point', color:'#f97316', kind:'point' },
    'osinergmin-mt-tramo':  { layer:22, nombre:'Tramos media tensión', tipo:'line', color:'#fb923c', kind:'mt' },
    'osinergmin-conc':   { layer:33, nombre:'Área de concesión', tipo:'polygon', color:'#22c55e', kind:'polygon' },
  };

  const FIELD_LABELS = {
    EMPRESA:'Empresa', NOMBRE:'Nombre', COD_LINEA:'Código línea', COD_SE:'Código SET',
    TENSION_NOM:'Tensión nominal', CAPACIDAD:'Capacidad', POT_INST:'Potencia instalada',
    LONGITUD:'Longitud km', NODO_SALIDA:'Nodo salida', NODO_LLEGADA:'Nodo llegada',
    DEPARTAMENTO:'Departamento', PROVINCIA:'Provincia', DISTRITO:'Distrito',
    TIPO_EMPRESA:'Tipo empresa', TIPO_SISTEMA:'Sistema', ESTADO:'Estado',
    TRANSFORMADORES:'Transformadores', CODEMP:'Código empresa',
    NAME:'Nombre', NOMBRE_ZON:'Zona', SITUACION_:'Situación', CONCESION:'Concesión',
    EXPEDIENTE:'Expediente', RECURSO:'Recurso', RUC:'RUC',
    TIPO_CONCE:'Tipo concesión', SISTEMA_EL:'Sistema eléctrico', ESTADO_VISOR:'Estado visor',
    FUENTE:'Fuente',
    _os_layer_name:'Subcapa OSINERGMIN', _os_categoria:'Categoria', _os_tecnologia:'Tecnologia', _os_estado:'Estado oficial',
    TIPO_CENTRAL:'Tipo de central', FUENTE_ENER:'Fuente energetica', POT_EFEC:'Potencia efectiva',
    POT_INSTALADA:'Potencia instalada', PUEST_OPER:'Puesta en operacion',
    TITULAR:'Titular', RESOLUCION_PRIMIGENIA:'Resolucion', AREA_TOTAL_HA:'Area total ha',
    TIPO_RECURSO:'Tipo recurso', NOMBRE_RECURSO:'Recurso', ESPECIE:'Especie',
    AMBITO:'Ambito', TIPO_DERECHO:'Tipo derecho', CATEGORIA_PRODUCTIVA:'Categoria productiva',
    CODTRAMOMT:'Codigo tramo MT', CODSALIDAMT:'Codigo salida MT', CODTIPORED:'Tipo de red',
    CODEMP_DESC:'Empresa', PROPIEDAD:'Propiedad', FECPUESTASERVICIO:'Puesta en servicio',
    FECPUESTAS:'Puesta en servicio', MAXDEMREGISTRADA:'Maxima demanda registrada',
    POTNOMINAL:'Potencia nominal',
    NOM_SE:'Sector agrícola', MNSA_2024:'Superficie agrícola 2024 ha', USO:'Uso',
    cira:'CIRA', nomcira:'CIRA', idcira:'ID CIRA', expediente:'Expediente', proyecto:'Proyecto', ubigeo:'Ubigeo',
    nomdpto:'Region', nomprov:'Provincia', nomdist:'Distrito', latitud:'Latitud', longitud:'Longitud',
  };

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, ch => ({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;',
    }[ch]));
  }
  function pick(props, keys, fallback='') {
    for (const k of keys) {
      const v = props?.[k];
      if (v !== undefined && v !== null && String(v).trim() !== '') return v;
    }
    return fallback;
  }
  function prettyLabel(key) {
    const fixed = {_api_layer:'Capa', _api_name:'API', _row:'Fila'};
    if (fixed[key]) return fixed[key];
    return FIELD_LABELS[key] || String(key).replace(/^_+/, '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }
  function formatAttrValue(key, value) {
    if (value === null || value === undefined || value === '') return '';
    if (typeof value === 'object') return JSON.stringify(value);
    const raw = String(value).trim();
    const n = Number(raw);
    const isNum = raw !== '' && Number.isFinite(n) && !/[A-Za-z]/.test(raw);
    const k = String(key || '').toLowerCase();
    if (isNum && (k.includes('longitud') || k === 'long' || k.includes('km'))) return `${f2(n,2)} km`;
    if (isNum && /\.\d{3,}/.test(raw)) return f2(n,2);
    return raw;
  }
  function attrRows(props, keys=null, includeAll=false) {
    const skip = new Set(['geometry','geom','geojson','geom_json','st_asgeojson','the_geom','wkb_geometry','shape']);
    const list = includeAll ? Object.keys(props || {}) : (keys || []);
    return list
      .filter(k => !skip.has(k) && props?.[k] !== undefined && props?.[k] !== null && String(props[k]).trim() !== '')
      .map(k => `<div class="attr-row"><span class="attr-k">${esc(prettyLabel(k))}</span><span class="attr-v">${esc(formatAttrValue(k, props[k]))}</span></div>`)
      .join('');
  }
  function imageRows(props) {
    const urls = Object.entries(props || {})
      .filter(([k, v]) => /foto|imagen|image|url/i.test(k) && /^https?:\/\/.+\.(png|jpe?g|webp)(\?.*)?$/i.test(String(v || '').trim()))
      .map(([k, v]) => `<figure class="attr-img"><img src="${esc(String(v).trim())}" alt="${esc(prettyLabel(k))}" loading="lazy"/></figure>`);
    return urls.slice(0, 3).join('');
  }
  const MT_COMPANIES = {
    EDLN:'ENEL', ELN:'Electro Norte', COEL:'COELVISAC', ELC:'Electro Centro',
    ELDU:'Electro Dunas', ELNO:'Electro Noroeste', ELOR:'Electro Oriente',
    EPAN:'Electro Pangoa', ELPU:'Electro Puno', ELS:'Electro Sur',
    ELSE:'Electro Sur Este', ELTO:'Electro Tocache', ELUC:'Electro Ucayali',
    ELNM:'Hidrandina', LDS:'Luz del Sur', SEAL:'SEAL', EMSEMSA:'EMSE',
    EMSEUSA:'EMSU', SERSA:'SERSA', EIHC:'Eilhicha', ADIL:'ADINELSA',
  };
  const MT_TYPE = { A:'Aerea', S:'Subterranea', M:'Mixta' };
  const MT_PROPERTY = { D:'Distribuidora', T:'Terceros', P:'Propia', E:'Empresa' };
  function decodeMtCompany(props={}) {
    return pick(props, ['CODEMP_DESC','EMPRESA','TITULAR','PROPIETARIO'], '') || MT_COMPANIES[String(props.CODEMP || '').trim().toUpperCase()] || pick(props, ['CODEMP'], '');
  }
  function decodeMtType(value) {
    const raw = String(value || '').trim().toUpperCase();
    return MT_TYPE[raw] ? `${MT_TYPE[raw]} (${raw})` : raw;
  }
  function decodeMtProperty(value) {
    const raw = String(value || '').trim().toUpperCase();
    return MT_PROPERTY[raw] ? `${MT_PROPERTY[raw]} (${raw})` : raw;
  }
  function formatMtLength(value) {
    const n = Number(String(value ?? '').replace(',', '.'));
    if (!Number.isFinite(n)) return String(value ?? '');
    return n >= 1000 ? `${f2(n / 1000, 2)} km (${f2(n, 2)} m)` : `${f2(n, 2)} m`;
  }
  function isMtFeature(props={}, title='', source='') {
    const text = normSearchText(`${title} ${source} ${props.FUENTE_ARCHIVO || ''} ${props.CODTRAMOMT || ''} ${props.CODSALIDAMT || ''}`);
    return !!pick(props, ['CODTRAMOMT','CODSALIDAMT','CODTIPORED','MAXDEMREGISTRADA','POTNOMINAL'], '')
      || text.includes('media tension') || text.includes('tramo mt') || text.includes('salida mt');
  }
  function isMtLayerConfig(id='', cfg={}) {
    const text = normSearchText(`${id} ${cfg?.nombre || ''} ${cfg?.grupo || ''} ${cfg?.menu || ''}`);
    return text.includes('media tension') || text.includes('tramo media') || text.includes('tramo mt');
  }
  function mtVoltageText(props={}) {
    const raw = pick(props, ['TENSION_NOM','TENSION_NOMINAL','TENSION','TENSION_KV','NIVEL_TENSION','KV'], '');
    return raw ? `${raw} kV` : 'Media tension - kV no publicado en la capa OSINERGMIN/archivo';
  }
  function mtPopup(props, title, source, exportId='') {
    const rows = [
      ['Empresa', decodeMtCompany(props)],
      ['Codigo empresa', pick(props, ['CODEMP'], '')],
      ['Codigo tramo MT', pick(props, ['CODTRAMOMT'], '')],
      ['Codigo salida MT', pick(props, ['CODSALIDAMT'], '')],
      ['Nivel de tension', mtVoltageText(props)],
      ['Tipo de red', decodeMtType(pick(props, ['CODTIPORED'], ''))],
      ['Longitud', formatMtLength(pick(props, ['LONGITUD'], ''))],
      ['Propiedad', decodeMtProperty(pick(props, ['PROPIEDAD'], ''))],
      ['Puesta en servicio', pick(props, ['FECPUESTASERVICIO','FECPUESTAS','FECHA_PS'], '')],
      ['Maxima demanda registrada', pick(props, ['MAXDEMREGISTRADA'], '')],
      ['Potencia nominal', pick(props, ['POTNOMINAL'], '')],
      ['Fuente', pick(props, ['FUENTE','FUENTE_ARCHIVO'], source)],
    ].filter(([,v]) => v !== undefined && v !== null && String(v).trim() !== '')
      .map(([k,v]) => `<div class="attr-row"><span class="attr-k">${esc(k)}</span><span class="attr-v">${esc(v)}</span></div>`)
      .join('');
    const exportBtns = exportId ? `<div class="export-actions"><button type="button" data-export-feature="${esc(exportId)}">Descargar KML</button><button type="button" data-export-add="${esc(exportId)}">Agregar a seleccion</button></div>` : '';
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">Media tension</span>
        <div class="pu-name">${esc(pick(props, ['CODTRAMOMT','CODSALIDAMT'], title || 'Tramo MT'))}</div>
        <div class="pu-emp">${esc(decodeMtCompany(props) || source)}</div>
      </div>
      <div class="pu-body" style="max-height:340px;overflow-y:auto">
        ${rows || '<div class="attr-row"><span class="attr-v">Sin atributos publicados.</span></div>'}
        <div class="line-note">La capa publica el tramo como red de media tension. Si no aparece kV, OSINERGMIN no lo expone en los atributos descargables de esta capa.</div>
        ${exportBtns}
      </div>
    </div>`;
  }
  function genPowerValue(props={}) {
    const raw = pick(props, ['POT_INST','POT_INSTALADA','POT_EFEC'], '');
    const n = Number(String(raw ?? '').replace(',', '.'));
    if (!Number.isFinite(n)) return 0;
    return n > 10000 ? n / 1000 : n;
  }
  function formatGenPower(value) {
    const n = Number(String(value ?? '').replace(',', '.'));
    if (!Number.isFinite(n)) return String(value ?? '');
    const mw = n > 10000 ? n / 1000 : n;
    return `${f2(mw, mw >= 10 ? 1 : 2)} MW`;
  }
  function formatGenDate(value) {
    if (value === null || value === undefined || value === '') return '';
    const n = Number(value);
    if (Number.isFinite(n) && Math.abs(n) > 100000000000) {
      const d = new Date(n);
      return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString('es-PE');
    }
    return String(value);
  }
  const GENERATION_TYPES = [
    { key:'hidro', label:'Hidroelectrica', color:'#2563eb', mark:'H' },
    { key:'termica', label:'Termoelectrica', color:'#ef4444', mark:'T' },
    { key:'solar', label:'Solar', color:'#f59e0b', mark:'S' },
    { key:'eolica', label:'Eolica', color:'#14b8a6', mark:'E' },
    { key:'biomasa', label:'Biomasa / biogas', color:'#65a30d', mark:'B' },
    { key:'aislada', label:'Generacion aislada', color:'#7c3aed', mark:'A' },
    { key:'rer', label:'RER / renovable', color:'#22c55e', mark:'R' },
    { key:'otra', label:'Otra generacion', color:'#64748b', mark:'G' },
  ];
  function generationType(props={}) {
    const text = normSearchText(`${props._os_layer || ''} ${props._os_layer_name || ''} ${props._os_tecnologia || ''} ${props.TIPO_CENTRAL || ''} ${props.FUENTE_ENER || ''} ${props.FUENTE || ''} ${props.CONDICION || ''}`);
    if (String(props._os_layer || '') === '5' || text.includes('aislada') || text.includes('municipio')) return GENERATION_TYPES[5];
    if (text.includes('solar') || text.includes('sol') || text.includes('fotovolta')) return GENERATION_TYPES[2];
    if (text.includes('eolic') || text.includes('viento')) return GENERATION_TYPES[3];
    if (text.includes('biomasa') || text.includes('biogas') || text.includes('bagazo')) return GENERATION_TYPES[4];
    if (text.includes('term') || text.includes('gas') || text.includes('diesel') || text.includes('carbon')) return GENERATION_TYPES[1];
    if (text.includes('hidro') || text.includes('rio') || text.includes('laguna')) return GENERATION_TYPES[0];
    if (text.includes('rer') || text.includes('renovable')) return GENERATION_TYPES[6];
    return GENERATION_TYPES[7];
  }
  function generationColor(props={}, fallback='#ef4444') {
    return generationType(props).color || fallback;
  }
  function generationRadius(props={}) {
    const mw = genPowerValue(props);
    if (mw >= 300) return 9;
    if (mw >= 100) return 7.5;
    if (mw >= 30) return 6;
    return 4.8;
  }
  function generationIcon(props={}) {
    const type = generationType(props);
    const size = Math.max(19, Math.round(generationRadius(props) * 2 + 9));
    return L.divIcon({
      className: 'gen-marker',
      html: `<span style="--gen-color:${type.color};width:${size}px;height:${size}px">${esc(type.mark)}</span>`,
      iconSize: [size, size],
      iconAnchor: [size / 2, size / 2],
      popupAnchor: [0, -size / 2],
    });
  }
  function generationPopup(props, cfg={}, exportId='') {
    const title = pick(props, ['NOMBRE','LOCALIDAD','SISTEMA_ELECTRICO'], cfg.nombre || 'Central de generacion');
    const empresa = pick(props, ['EMPRESA','PROPIETARIO'], '');
    const genType = generationType(props);
    const rows = [
      ['Tipo visual', genType.label],
      ['Subcapa', pick(props, ['_os_layer_name'], '')],
      ['Categoria', pick(props, ['_os_categoria'], '')],
      ['Tecnologia', pick(props, ['_os_tecnologia','TIPO_CENTRAL'], '')],
      ['Tipo de central', pick(props, ['TIPO_CENTRAL'], '')],
      ['Fuente energetica', pick(props, ['FUENTE_ENER','FUENTE'], '')],
      ['Potencia instalada', formatGenPower(pick(props, ['POT_INST','POT_INSTALADA'], ''))],
      ['Potencia efectiva', formatGenPower(pick(props, ['POT_EFEC'], ''))],
      ['Tension nominal', pick(props, ['TENSION_NOM'], '') ? `${pick(props, ['TENSION_NOM'], '')} kV` : ''],
      ['Estado', pick(props, ['CONDICION','_os_estado','ESTADO'], '')],
      ['Sistema', pick(props, ['TIPO_SISTEMA','SISTEMA','SISTEMA_ELECTRICO'], '')],
      ['Puesta en operacion', formatGenDate(pick(props, ['PUEST_OPER'], ''))],
      ['Departamento', pick(props, ['DEPARTAMENTO'], '')],
      ['Provincia', pick(props, ['PROVINCIA'], '')],
      ['Distrito', pick(props, ['DISTRITO'], '')],
      ['Localidad', pick(props, ['LOCALIDAD'], '')],
    ].filter(([,v]) => v !== undefined && v !== null && String(v).trim() !== '')
      .map(([k,v]) => `<div class="attr-row"><span class="attr-k">${esc(k)}</span><span class="attr-v">${esc(v)}</span></div>`)
      .join('');
    const exportBtns = exportId ? `<div class="export-actions"><button type="button" data-export-feature="${esc(exportId)}">Descargar KML</button><button type="button" data-export-add="${esc(exportId)}">Agregar a seleccion</button></div>` : '';
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">Generacion OSINERGMIN</span>
        <div class="pu-name">${esc(title)}</div>
        ${empresa ? `<div class="pu-emp">${esc(empresa)}</div>` : ''}
      </div>
      <div class="pu-body" style="max-height:360px;overflow-y:auto">
        <div class="pu-attrs">${rows || '<div class="attr-row"><span class="attr-v">Sin atributos publicados.</span></div>'}</div>
        ${exportBtns}
      </div>
    </div>`;
  }
  const VOLTAGE_LEGEND = [
    { kv:33,  label:'33 kV',  color:'#ec4899' },
    { kv:60,  label:'60 kV',  color:'#dc2626' },
    { kv:138, label:'138 kV', color:'#16a34a' },
    { kv:220, label:'220 kV', color:'#2563eb' },
    { kv:500, label:'500 kV', color:'#f97316' },
  ];
  function voltageValue(props) {
    const raw = String(pick(props, [
      'TENSION_NOM','TENSION_NOMINAL','TENSION','TENSION_KV','TENSIONKV',
      'TENSION_NO','TENSIONNO','MAX_TENS_OPERAC','MAX_TENS_O',
      'NIVEL_TENS','NIVEL_TENSION','TENSION_LIN','TEN_NOM','KV'
    ], ''));
    if (!raw) return 0;
    const nums = raw.match(/\d+(?:[.,]\d+)?/g)?.map(v => Number(v.replace(',', '.'))).filter(Number.isFinite) || [];
    return nums.length ? Math.max(...nums) : 0;
  }
  function voltageBucket(kv) {
    if (kv >= 500) return VOLTAGE_LEGEND[4];
    if (kv >= 220) return VOLTAGE_LEGEND[3];
    if (kv >= 138) return VOLTAGE_LEGEND[2];
    if (kv >= 60) return VOLTAGE_LEGEND[1];
    if (kv > 0) return VOLTAGE_LEGEND[0];
    return null;
  }
  function voltageColor(props, fallback) {
    return voltageBucket(voltageValue(props))?.color || fallback;
  }
  function lineColor(props, fallback) {
    return voltageColor(props, fallback);
  }
  function isSetLayer(id='', cfg={}) {
    const s = normSearchText(`${id} ${cfg?.nombre || ''} ${cfg?.grupo || ''} ${cfg?.menu || ''}`);
    return /\bset\b/.test(s) || s.includes('subestacion');
  }
  function isSetFeature(props={}, title='') {
    const s = normSearchText(`${title} ${props.NOMBRE || ''} ${props.NAME || ''} ${props.COD_SE || ''} ${props.NOMBRE_SE || ''}`);
    return !!pick(props, ['COD_SE','NOMBRE_SE'], '') || /\bset\b/.test(s) || s.includes('subestacion');
  }
  function lineCodeValue(props={}) {
    return pick(props, ['COD_LINEA','COD_LINEA_1','CODIGO_LINEA','CODIGO','COD_LIN','CODIGO_LT'], '');
  }
  function setVoltageIcon(props={}, fallback='#0ea5e9') {
    const color = voltageColor(props, fallback);
    return L.divIcon({
      className:'set-v-icon',
      html:`<span style="--set-color:${esc(color)}"></span>`,
      iconSize:[16,16],
      iconAnchor:[8,8],
      popupAnchor:[0,-8],
    });
  }
  function layerCenter(layer) {
    if (layer.getLatLng) return layer.getLatLng();
    if (layer.getBounds) return layer.getBounds().getCenter();
    return null;
  }
  function normSearchText(value) {
    return String(value ?? '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/\s+/g, ' ').trim();
  }
  function searchTitle(props, fallback='Elemento') {
    return String(pick(props, [
      'NOMBRE','NAME','NOMBRE_LINEA','COD_LINEA','CODTRAMOMT','CODSALIDAMT','COD_SE','CONCESION','NOMBRE_ZON',
      'cira','proyecto','DENOM','TITULAR','PROPIETARIO','EMPRESA','DISTRITO','PROVINCIA'
    ], fallback) || fallback);
  }
  function searchSubtitle(props, source='') {
    return [
      pick(props, ['EMPRESA','CODEMP_DESC','TITULAR','PROPIETARIO','CODEMP','RUC'], ''),
      pick(props, ['DEPARTAMENTO','PROVINCIA','DISTRITO','ESTADO','SITUACION_','COND_JUR'], ''),
      source,
    ].filter(Boolean).map(v => String(v).trim()).filter(Boolean).slice(0, 3).join(' | ');
  }
  function searchKindLabel(kind, source='', title='') {
    const s = normSearchText(`${kind} ${source} ${title}`);
    if (s.includes('api externa')) return 'API';
    if (kind === 'generation' || s.includes('generacion') || s.includes('central')) return 'Generacion';
    if (kind === 'set' || s.includes('subestacion') || /\bset\b/.test(s)) return 'SET';
    if (kind === 'mt' || s.includes('media tension')) return 'MT';
    if (kind === 'line' || s.includes('linea') || /\blt\b/.test(s)) return 'LT';
    if (s.includes('conces')) return 'Concesión';
    if (kind === 'polygon') return 'Polígono';
    if (kind === 'point') return 'Punto';
    return 'Capa';
  }
  function addFeatureIndex(props, layer, latlng, kind, source, fallback='') {
    if (!latlng) return;
    if (kind === 'line' && layer?.on) {
      layer.on('click', event => {
        if (event.originalEvent) event.originalEvent._cvcLineHandled = true;
      });
    }
    const values = Object.entries(props || {})
      .filter(([k, v]) => v !== null && v !== undefined && typeof v !== 'object' && String(v).trim() !== '' && String(k).charAt(0) !== '_')
      .slice(0, 42)
      .map(([, v]) => String(v))
      .join(' ');
    const title = searchTitle(props, fallback || source || 'Elemento');
    const subtitle = searchSubtitle(props, source);
    const text = [title, subtitle, values, source, kind].join(' ');
    const norm = normSearchText(text);
    if (!norm) return;
    const propSignal = normSearchText(Object.keys(props || {}).join(' ') + ' ' + values);
    let label = searchKindLabel(kind, source, title);
    if (label === 'Polígono' && (propSignal.includes('conces') || propSignal.includes('situacion') || propSignal.includes('expediente'))) {
      label = 'Concesión';
    }
    SIG.featureIndex.push({
      text:norm,
      title,
      subtitle,
      label,
      kind,
      source,
      layer,
      latlng,
      properties:{...(props || {})},
    });
  }
  function updateMiniStats(metric, count) {
    if (metric === 'set') ['kpiSetCount','gSet'].forEach(id => {
      const el = document.getElementById(id); if (el) el.textContent = count.toLocaleString('es-PE');
    });
    if (metric === 'lt') ['kpiLtCount','gLt'].forEach(id => {
      const el = document.getElementById(id); if (el) el.textContent = count.toLocaleString('es-PE');
    });
    const gCapas = document.getElementById('gCapas');
    if (gCapas) gCapas.textContent = document.querySelectorAll('.lyr.on').length;
  }
  function lineAnalysisId(props) {
    return `line-${String(pick(props, ['OBJECTID','COD_LINEA','COD_LINEA_1','CODIGO_LINEA','CODIGO','COD_LIN','NOMBRE','NOMBRE_LINEA'], Math.random())).replace(/[^a-zA-Z0-9_-]/g,'')}`;
  }
  function ptLookupId(props, type='pt') {
    return `${type}-${String(pick(props, ['OBJECTID','COD_LINEA','COD_LINEA_1','CODIGO_LINEA','CODIGO','COD_LIN','COD_SE','NOMBRE','NOMBRE_LINEA','NOMBRE_SE'], Math.random())).replace(/[^a-zA-Z0-9_-]/g,'')}`;
  }
  function renderLoadChart(series, opts={}) {
    const points = (series || []).filter(p => Number.isFinite(Number(p.mva)));
    if (points.length < 2) return '';
    const w = 320, h = 105, pad = 24;
    const vals = points.map(p => Number(p.mva));
    const min = Math.min(...vals), max = Math.max(...vals);
    const span = Math.max(max - min, 0.001);
    const xy = points.map((p, i) => {
      const x = pad + (i * (w - pad * 2)) / Math.max(1, points.length - 1);
      const y = h - pad - ((Number(p.mva) - min) / span) * (h - pad * 2);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
    const first = points[0], last = points[points.length - 1];
    const title = opts.title || 'Consumo registrado';
    return `<div class="load-chart">
      <div class="load-chart-head">
        <span>${esc(title)}</span>
        <strong>${f2(last.mva,2)} MVA</strong>
      </div>
      <svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${esc(title)}">
        <line x1="${pad}" y1="${h-pad}" x2="${w-pad}" y2="${h-pad}" class="axis"/>
        <line x1="${pad}" y1="${pad}" x2="${pad}" y2="${h-pad}" class="axis"/>
        <polyline points="${xy}" class="load-line"/>
        <circle cx="${xy.split(' ').at(-1).split(',')[0]}" cy="${xy.split(' ').at(-1).split(',')[1]}" r="3.4" class="load-last"/>
      </svg>
      <div class="load-chart-foot">
        <span>${esc(first.fecha || first.periodo || '')}</span>
        <span>${esc(last.fecha || last.periodo || '')}</span>
      </div>
    </div>`;
  }
  function renderBarChart(series, opts={}) {
    const points = (series || []).filter(p => Number.isFinite(Number(p.mva)));
    if (!points.length) return '';
    const w = 320, h = 132, pad = 24;
    const vals = points.map(p => Number(p.mva));
    const rawMax = Math.max(...vals);
    const max = Math.max(rawMax, 0.001);
    const bw = Math.max(8, (w - pad * 2) / points.length - 5);
    const maxIdx = Math.max(0, vals.indexOf(rawMax));
    const bars = points.map((p, i) => {
      const x = pad + i * ((w - pad * 2) / points.length) + 2;
      const bh = Math.max(2, (Number(p.mva) / max) * (h - pad * 2));
      const y = h - pad - bh;
      const cls = i === maxIdx ? 'load-bar load-bar-max' : 'load-bar';
      return `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${bw.toFixed(1)}" height="${bh.toFixed(1)}" class="${cls}">
        <title>${esc(p.mes || p.periodo || p.fecha || '')}: ${f2(p.mva,2)} MVA</title>
      </rect>`;
    }).join('');
    const labels = points.map((p, i) => {
      if (points.length > 12 && i % Math.ceil(points.length / 6) !== 0 && i !== points.length - 1) return '';
      const x = pad + i * ((w - pad * 2) / points.length) + bw / 2 + 2;
      const label = String(p.mes || p.periodo || '').replace(/\s+\d{4}$/, '').slice(0, 3);
      return `<text x="${x.toFixed(1)}" y="${h - 7}" text-anchor="middle" class="bar-label">${esc(label)}</text>`;
    }).join('');
    const peak = points[maxIdx];
    const title = opts.title || 'Maximo mensual registrado';
    return `<div class="load-chart bar-chart">
      <div class="load-chart-head">
        <span>${esc(title)}</span>
        <strong>${f2(peak.mva,2)} MVA</strong>
      </div>
      <svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${esc(title)}">
        <line x1="${pad}" y1="${h-pad}" x2="${w-pad}" y2="${h-pad}" class="axis"/>
        <line x1="${pad}" y1="${pad}" x2="${pad}" y2="${h-pad}" class="axis"/>
        ${bars}${labels}
      </svg>
      <div class="load-chart-foot">
        <span>${esc(points[0].mes || points[0].periodo || '')}</span>
        <span>${esc(peak.fecha || peak.periodo || '')}</span>
      </div>
    </div>`;
  }
  function featurePopup(props, title, source, exportId='') {
    if (isMtFeature(props, title, source)) return mtPopup(props, title, source, exportId);
    const main = esc(pick(props, ['NOMBRE','NAME','cira','proyecto','CONCESION','NOMBRE_ZON','COD_LINEA','COD_SE','CODEMP','PROPIETARIO','PROPIETARIOS','OWNER','TITULAR'], title));
    const empresa = pick(props, ['EMPRESA','PROPIETARIO','PROPIETARIOS','OWNER','TITULAR'], '');
    const keys = ['EMPRESA','CONCESION','NOMBRE_ZON','SITUACION_','ESTADO_VISOR','EXPEDIENTE','RUC',
                  'TIPO_CONCE','SISTEMA_EL','COD_LINEA','COD_SE','TENSION_NOM','CAPACIDAD','POT_INST','LONGITUD',
                  'NODO_SALIDA','NODO_LLEGADA','DEPARTAMENTO','PROVINCIA','DISTRITO',
                  'TIPO_EMPRESA','TIPO_SISTEMA','ESTADO','TRANSFORMADORES','CODEMP','FUENTE'];
    const openAttrs = source === 'API externa' || source === 'Ministerio de Cultura';
    const rows = openAttrs ? attrRows(props, null, true) : attrRows(props, keys, false);
    const imgs = openAttrs ? imageRows(props) : '';
    const setBtn = source !== 'API externa' && isSetFeature(props, title)
      ? `<button type="button" class="btn-analyze" id="ab-${setAnalysisId(props)}">Analizar disponibilidad de carga</button><div id="ar-${setAnalysisId(props)}"></div>`
      : '';
    const lineBtn = source !== 'API externa' && pick(props, ['COD_LINEA','COD_LINEA_1','CODIGO_LINEA','CODIGO'], '') ? `<button type="button" class="btn-analyze" id="lb-${lineAnalysisId(props)}">Ver carga de línea</button><div id="lr-${lineAnalysisId(props)}"></div>` : '';
    const exportBtns = exportId ? `<div class="export-actions"><button type="button" data-export-feature="${esc(exportId)}">Descargar KML</button><button type="button" data-export-add="${esc(exportId)}">Agregar a seleccion</button></div>` : '';
    const ciraLink = source === 'Ministerio de Cultura'
      ? '<a class="pdf-link" href="https://aplicaciones.cultura.gob.pe/app-cira/pages/general/pgw_consultaCira.seam" target="_blank" rel="noopener">Consultar PDF CIRA oficial</a>'
      : '';
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">${esc(source)}</span>
        <div class="pu-name">${main}</div>
        ${empresa ? `<div class="pu-emp">${esc(empresa)}</div>` : ''}
      </div>
      <div class="pu-body" style="max-height:320px;overflow-y:auto">${imgs}${rows || '<div class="attr-row"><span class="attr-v">Sin atributos publicados.</span></div>'}${ciraLink}${exportBtns}${setBtn}${lineBtn}</div>
    </div>`;
  }

  function setAnalysisId(props) {
    return `set-${String(pick(props, ['OBJECTID','COD_SE','NOMBRE','NOMBRE_SE'], Math.random())).replace(/[^a-zA-Z0-9_-]/g,'')}`;
  }
  function officialSetPopup(props, cfg) {
    const id = setAnalysisId(props);
    const main = esc(pick(props, ['NOMBRE','NOMBRE_SE','COD_SE'], cfg.nombre));
    const empresa = pick(props, ['EMPRESA'], '');
    const keys = ['EMPRESA','COD_SE','TENSION_NOM','CAPACIDAD','POT_INST','TRANSFORMADORES',
                  'DEPARTAMENTO','PROVINCIA','DISTRITO','ESTADO'];
    const rows = attrRows(props, keys, false);
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">OSINERGMIN</span>
        <div class="pu-name">${main}</div>
        ${empresa ? `<div class="pu-emp">${esc(empresa)}</div>` : ''}
      </div>
      <div class="pu-body">
        <div class="pu-attrs">${rows}</div>
        <button type="button" class="btn-analyze" id="ab-${id}">Analizar disponibilidad de carga</button>
        <div id="ar-${id}"></div>
      </div>
    </div>`;
  }
  function officialLinePopup(props, cfg, exportId='') {
    const id = lineAnalysisId(props);
    const main = esc(pick(props, ['NOMBRE','NOMBRE_LINEA','COD_LINEA','COD_LINEA_1'], cfg.nombre));
    const empresa = pick(props, ['EMPRESA'], '');
    const keys = ['EMPRESA','COD_LINEA','NOMBRE','TENSION_NOM','LONGITUD','CORRIENTE_NOM','CAPAC_NOMINAL',
                  'NODO_SALIDA','NODO_LLEGADA','TIPO_CIRCUITO','SISTEMA','ESTADO'];
    const rows = attrRows(props, keys, false);
    const exportBtns = exportId ? `<div class="export-actions"><button type="button" data-export-feature="${esc(exportId)}">Descargar KML</button><button type="button" data-export-add="${esc(exportId)}">Agregar a seleccion</button></div>` : '';
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">OSINERGMIN</span>
        <div class="pu-name">${main}</div>
        ${empresa ? `<div class="pu-emp">${esc(empresa)}</div>` : ''}
      </div>
      <div class="pu-body">
        <div class="pu-attrs">${rows}</div>
        ${exportBtns}
        <button type="button" class="btn-analyze" id="lb-${id}">Ver carga de línea</button>
        <div id="lr-${id}"></div>
      </div>
    </div>`;
  }
  function statusClass(state) {
    const s = String(state || '').toLowerCase();
    return (s.includes('sobrecarga') || s.includes('sin margen')) ? 'st-no' : (s.includes('sin capacidad') || s.includes('sin medicion') || s.includes('sin datos')) ? 'st-warn' : 'st-ok';
  }
  function companyDisplay(value, fallback='Sin empresa') {
    const raw = String(value || '').trim();
    const n = raw.toUpperCase();
    if (!raw) return fallback;
    if (n === 'ENO') return 'ENOSA';
    if (n === 'PMT') return 'Puerto Maldonado Transmisora';
    if (n.includes('ELECTRONOROESTE')) return 'ENOSA';
    if (n.includes('RED DE ENERGIA DEL PERU') || n.includes('RED DE ENERGÍA DEL PERÚ')) return 'REP';
    return raw;
  }
  const naturalCollator = new Intl.Collator('es', { numeric:true, sensitivity:'base' });
  function companyKey(value) {
    return normSearchText(companyDisplay(value, value || 'Sin empresa'))
      .replace(/\b(s|sa|saa|sac|eirl|ltda|empresa|concesionaria)\b/g, ' ')
      .replace(/\b(xlsx|xls|csv|quinceminutales|excel)\b/g, ' ')
      .replace(/\s+/g, ' ')
      .trim() || 'sin empresa';
  }
  function companyFromFile(file='') {
    const stem = String(file || '').split(/[\\/]/).pop().replace(/\.(xlsx|xls|csv)$/i, '');
    return companyDisplay(stem, '');
  }
  function analysisCompanyLabel(row={}, data={}) {
    return companyDisplay(
      row.empresa_capacidad || row.empresa_quinceminutal || data.empresa_consulta || data.subestacion?.empresa || companyFromFile(row.archivo_quinceminutal),
      companyFromFile(row.archivo_quinceminutal) || data.empresa_consulta || 'Sin empresa'
    );
  }
  function compareSetRows(a={}, b={}) {
    const transformerOrder = naturalCollator.compare(String(a.transformador || ''), String(b.transformador || ''));
    if (transformerOrder) return transformerOrder;
    const tensionA = Number(a.tension_kv);
    const tensionB = Number(b.tension_kv);
    if (Number.isFinite(tensionA) && Number.isFinite(tensionB) && tensionA !== tensionB) return tensionB - tensionA;
    return naturalCollator.compare(String(a.barra || ''), String(b.barra || ''));
  }
  function groupSetRowsByCompany(rows, data={}) {
    const out = new Map();
    (rows || []).forEach(r => {
      const label = analysisCompanyLabel(r, data);
      const key = companyKey(label);
      if (!out.has(key)) out.set(key, { label, rows: [] });
      const group = out.get(key);
      if (group.label === 'Sin empresa' && label !== 'Sin empresa') group.label = label;
      group.rows.push(r);
    });
    return [...out.values()]
      .map(g => [g.label, g.rows.sort(compareSetRows)])
      .sort((a,b) => naturalCollator.compare(a[0], b[0]));
  }
  function monthlyForRows(data, rows) {
    const files = new Set((rows || []).map(r => r.archivo_quinceminutal).filter(Boolean));
    if (!files.size) return [];
    const byFile = Array.isArray(data.consumo_mensual_por_archivo) ? data.consumo_mensual_por_archivo : [];
    const picked = byFile.filter(g => files.has(g.archivo));
    if (picked.length === 1) return picked[0].mensual || [];
    if (picked.length > 1) {
      const byPeriod = new Map();
      picked.forEach(g => (g.mensual || []).forEach(m => {
        const key = m.periodo || m.mes || '';
        if (!key) return;
        const cur = byPeriod.get(key);
        if (!cur || Number(m.mva || 0) > Number(cur.mva || 0)) {
          byPeriod.set(key, {...m, criterio:'Mayor entrada primaria entre archivos; no se suman maximos no simultaneos'});
        }
      }));
      return [...byPeriod.values()].sort((a,b) => String(a.periodo || '').localeCompare(String(b.periodo || '')));
    }
    return [];
  }
  function renderAvailabilityRows(rows) {
    const referenceState = (r={}) => {
      const has = value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
      if (!has(r.capacidad_mva) || !has(r.demanda_simultanea_mva)) return 'Sin datos';
      const windingOverload = Number(r.demanda_simultanea_mva) > Number(r.capacidad_mva) + 1e-6;
      const primaryOverload = has(r.pico_primario_mva) && has(r.capacidad_total_mva)
        && Number(r.pico_primario_mva) > Number(r.capacidad_total_mva) + 1e-6;
      if (windingOverload || primaryOverload) return 'Sobrecarga';
      if (!has(r.disponible_referencia_mva)) return 'Sin datos';
      return Number(r.disponible_referencia_mva) > 1e-6 ? 'Con margen' : 'Sin margen';
    };
    return (rows || []).map(r => {
      const state = referenceState(r);
      return `
      <tr>
        <td>${esc(r.transformador)}${r.transformador_mapa ? `<small>${esc(r.transformador_mapa)}</small>` : ''}</td>
        <td>${esc(r.fecha_referencia_simultanea || '—')}</td>
        <td>${esc(r.barra || '—')}<small>${esc(r.tension_kv || '')}${r.tension_kv ? ' kV' : ''}</small></td>
        <td class="num">${r.capacidad_mva === null || r.capacidad_mva === undefined ? '—' : f2(r.capacidad_mva,2)}</td>
        <td class="num">${r.p_simultaneo_mw === null || r.p_simultaneo_mw === undefined ? '—' : f2(r.p_simultaneo_mw,2)}</td>
        <td class="num">${r.q_simultaneo_mvar === null || r.q_simultaneo_mvar === undefined ? '—' : f2(r.q_simultaneo_mvar,2)}</td>
        <td class="num">${r.demanda_simultanea_mva === null || r.demanda_simultanea_mva === undefined ? '—' : f2(r.demanda_simultanea_mva,2)}</td>
        <td class="num ${r.disponible_referencia_mva === null || r.disponible_referencia_mva === undefined ? '' : (Number(r.disponible_referencia_mva) <= 0 ? 'bad' : 'good')}">${r.disponible_referencia_mva === null || r.disponible_referencia_mva === undefined ? '—' : f2(r.disponible_referencia_mva,2)}</td>
        <td><span class="mini-status ${statusClass(state)}">${esc(state)}</span></td>
      </tr>`;
    }).join('');
  }
  function renderSetCompanyGroups(data) {
    const groups = groupSetRowsByCompany(data.filas || [], data);
    const summary = new Map((data.resumen_transformadores || []).map(r => [String(r.transformador), r]));
    return groups.map(([label, rows]) => {
      const trafos = [...new Set(rows.map(r => String(r.transformador)))];
      const srows = trafos.map(t => summary.get(t)).filter(Boolean);
      const cap = srows.reduce((a,r) => a + Number(r.capacidad_primaria_mva ?? r.capacidad_total_mva ?? 0), 0);
      const noMeasurement = rows.every(r => r.sin_medicion_quinceminutal || /sin medicion/i.test(String(r.estado || '')))
        || ['sin_medicion','sin_registros'].includes(String(data.medicion_quinceminutal || ''));
      const files = [...new Set(rows.map(r => r.archivo_quinceminutal).filter(Boolean))].join(' | ');
      const tableRows = renderAvailabilityRows(rows);
      const measuredBars = rows.filter(r => r.demanda_simultanea_mva !== null && r.demanda_simultanea_mva !== undefined).length;
      return `<details class="set-company-group" open>
        <summary>
          <strong>${esc(label)}</strong>
          <span>${fi(rows.length)} barras · ${fi(trafos.length)} trafos${files ? ` · ${esc(files)}` : ''}</span>
        </summary>
        <div class="set-company-kpis">
          <div><span>Primaria instalada</span><strong>${f2(cap,2)} MVA</strong></div>
          <div><span>Transformadores</span><strong>${fi(trafos.length)}</strong></div>
          <div><span>Barras con lectura simultanea</span><strong>${noMeasurement ? '0' : fi(measuredBars)}</strong></div>
        </div>
        <div class="line-note">Lectura mostrada: P, Q y S de cada barra en la fecha y hora exactas del pico primario de su transformador. El disponible considera a la vez el límite del primario y el de la barra; los márgenes de distintas barras o transformadores no se suman. El detalle técnico completo permanece en los informes.</div>
        <div class="availability-table-wrap compact">
          <table class="availability-table">
            <thead><tr>
              <th>Trafo</th><th>Fecha y hora</th><th>Barra / tensión</th><th>Capacidad (MVA)</th><th>P (MW)</th><th>Q (MVAr)</th><th>S (MVA)</th><th>Disponible (MVA)</th><th>Estado</th>
            </tr></thead>
            <tbody>${tableRows || '<tr><td colspan="9">Sin resultados.</td></tr>'}</tbody>
          </table>
        </div>
      </details>`;
    }).join('');
  }
  function renderPitAnalysis(pit) {
    if (!pit || pit.status === 'unavailable') return '';
    if (pit.status === 'no_data') {
      return `<div class="pit-box"><div class="ana-ttl">Plan de inversiones PIT</div><div class="pu-st st-warn">${esc(pit.message || 'Sin elementos PIT asociados.')}</div></div>`;
    }
    const byCompany = new Map();
    (pit.matches || []).slice(0, 18).forEach(m => {
      const company = companyDisplay(m.empresa || m.empresa_norm, 'Sin empresa');
      if (!byCompany.has(company)) byCompany.set(company, []);
      byCompany.get(company).push(m);
    });
    const rows = [...byCompany.entries()].sort((a,b) => a[0].localeCompare(b[0], 'es')).map(([company, items]) => {
      const byPit = new Map();
      items.forEach(m => {
        const label = m.pit_etiqueta || m.pit_titulo || m.anexo || 'PIT';
        if (!byPit.has(label)) byPit.set(label, []);
        byPit.get(label).push(m);
      });
      const groups = [...byPit.entries()].map(([label, pitRows]) => `
        <div class="pit-subgroup">
          <div class="pit-subtitle">${esc(label)}</div>
          ${pitRows.map(m => `
            <div class="pit-row">
              <strong>${esc(m.elemento || 'Elemento PIT')}</strong>
              <span>${esc(m.instalacion || '')}</span>
              <em>${esc(m.estado || m.avance || 'Sin estado')}</em>
              ${m.tension_kv ? `<small>Tension: ${esc(m.tension_kv)} kV</small>` : ''}
              ${m.potencia_mva ? `<small>Potencia: ${esc(m.potencia_mva)} MVA</small>` : ''}
              ${m.area_demanda ? `<small>Area de demanda: ${esc(m.area_demanda)}</small>` : ''}
              ${m.fecha_suscripcion ? `<small>Suscripcion: ${esc(m.fecha_suscripcion)}</small>` : ''}
              ${m.fecha_puesta_servicio ? `<small>Puesta en servicio: ${esc(m.fecha_puesta_servicio)}</small>` : ''}
              ${m.fecha_reprogramada ? `<small>Reprogramada: ${esc(m.fecha_reprogramada)}</small>` : ''}
            </div>`).join('')}
        </div>`).join('');
      return `<details class="pit-company" open>
        <summary><strong>${esc(company)}</strong><span>${fi(items.length)} elementos PIT</span></summary>
        ${groups}
      </details>`;
    }).join('');
    const future = pit.comentario ? `<div class="line-note">${esc(pit.comentario)}</div>` : '';
    return `<div class="pit-box">
      <div class="ana-ttl">Plan de inversiones PIT</div>
      ${future}
      ${rows || '<div class="pu-st st-warn">Sin elementos PIT asociados.</div>'}
      <div class="analysis-source">Archivo PIT: ${esc(pit.file || 'Anexos PIT')} · Hojas revisadas: ${fi(pit.sheets_scanned || 0)}</div>
    </div>`;
  }
  function renderPtCoes(pt) {
    if (!pt || pt.status === 'unavailable') return '';
    if (pt.status === 'no_data' || !(pt.matches || []).length) {
      return `<div class="pit-box pt-box"><div class="ana-ttl">Plan de Transmisión COES 2027-2036</div><div class="pu-st st-warn">${esc(pt.message || 'Sin anteproyectos PT COES asociados.')}</div></div>`;
    }
    const rows = (pt.matches || []).slice(0, 8).map(m => `
      <div class="pit-row pt-row">
        <strong>${esc(m.titulo || m.archivo || 'Anteproyecto COES')}</strong>
        <span>${esc([m.codigo, m.tipo, m.tension_kv ? `${m.tension_kv} kV` : ''].filter(Boolean).join(' | '))}</span>
        ${m.razones?.length ? `<small>${esc(m.razones.slice(0, 2).join(' | '))}</small>` : ''}
        <em>${m.match_type === 'proyecto_nuevo_departamento' ? 'Proyecto nuevo del departamento consultado' : `Coincidencia explicita ${f2(m.score,0)}/100`}</em>
        ${m.url ? `<a class="pdf-link mini" href="${esc(m.url)}" target="_blank" rel="noopener">Abrir PDF COES</a>` : ''}
      </div>`).join('');
    return `<div class="pit-box pt-box">
      <div class="ana-ttl">Plan de Transmisión COES 2027-2036</div>
      <div class="line-note">Anteproyecto detectado cerca del elemento consultado. Revisar alcance, punto de partida y cronograma antes de tomar decisión.</div>
      ${rows}
      <div class="analysis-source">Documentos revisados: ${fi(pt.file_count || 0)} PDF</div>
    </div>`;
  }
  function renderSetAnalysis(data, reportId='') {
    const reportButtons = reportId ? `<div class="analysis-actions">
      <button type="button" class="btn-analyze report-btn" data-set-report="${esc(reportId)}" data-format="pdf">Exportar PDF</button>
      <button type="button" class="btn-analyze report-btn" data-set-report="${esc(reportId)}" data-format="docx">Exportar Word</button>
      <button type="button" class="btn-analyze report-btn" data-set-report="${esc(reportId)}" data-format="xlsx">Exportar Excel</button>
    </div>` : '';
    if (data.status === 'error') {
      return `<div class="ana-res"><div class="pu-st st-no">${esc(data.message || data.msg || 'No se pudo analizar.')}</div></div>`;
    }
    if (data.status === 'no_data') {
      return `<div class="ana-res">
        <div class="ana-ttl">Disponibilidad de carga</div>
        ${reportButtons}
        <div class="pu-st st-warn">${esc(data.mensaje || 'Sin registros quinceminutales.')}</div>
        ${data.mensaje_medicion ? `<div class="line-note">${esc(data.mensaje_medicion)}</div>` : ''}
        ${renderPitAnalysis(data.pit)}
        ${renderPtCoes(data.pt_coes)}
        ${data.pdf?.url ? `<a class="pdf-link" href="${esc(data.pdf.url)}" target="_blank" rel="noopener">Ver unifilar PDF</a>` : ''}
      </div>`;
    }
    const totals = (data.resumen_transformadores || []).map(r => `
      <div class="tr-summary ${statusClass(r.estado)}">
        <strong>${esc(r.transformador)}</strong>
        <span>${r.pico_primario_mva === null || r.pico_primario_mva === undefined ? 'Sin pico primario medido' : `Pico primario ${f2(r.pico_primario_mva,2)} MVA - ${esc(r.fecha_pico_primario || 'sin fecha')}`}</span>
        ${r.potencias_nominales_mva ? `<small>Placa ${esc(r.potencias_nominales_mva)} MVA; limite primario ${f2(r.capacidad_primaria_mva,2)} MVA</small>` : ''}
        ${r.barra_referencia ? `<small>Referencia: barra ${esc(r.barra_referencia)} - ${esc(r.tension_referencia_kv || '')} kV; disponible ${f2(r.disponible_referencia_mva,2)} MVA; planificacion ${f2(r.disponible_planificacion_referencia_mva,2)} MVA; limita ${esc(r.restriccion_referencia || 'por validar')}</small>` : ''}
      </div>`).join('');
    const capSource = (data.filas || []).some(r => /EquiposTransformadores/i.test(String(r.fuente_capacidad || '')))
      ? 'EquiposTransformadores.xlsx'
      : 'MapaSEIN OSINERGMIN / atributos del visor';
    return `<div class="ana-res">
      <div class="ana-ttl">Disponibilidad de carga</div>
      ${reportButtons}
      ${data.mensaje_medicion ? `<div class="line-note">${esc(data.mensaje_medicion)}</div>` : ''}
      ${data.advertencia_tecnica ? `<div class="line-note">${esc(data.advertencia_tecnica)}</div>` : ''}
      ${totals ? `<div class="tr-summary-wrap">${totals}</div>` : ''}
      ${renderPitAnalysis(data.pit)}
      ${renderPtCoes(data.pt_coes)}
      ${renderSetCompanyGroups(data) || renderBarChart(data.consumo_mensual, { title:'Maximo mensual registrado' })}
      <div class="analysis-source">Quinceminutal: ${esc(data.archivo_quinceminutal || '-')} | Capacidad: ${esc(capSource)}</div>
      ${data.pdf?.url ? `<a class="pdf-link" href="${esc(data.pdf.url)}" target="_blank" rel="noopener">Ver unifilar PDF</a>` : ''}
    </div>`;
  }
  async function runSetAvailability(props) {
    const id = setAnalysisId(props);
    const btn = document.getElementById(`ab-${id}`);
    const res = document.getElementById(`ar-${id}`);
    if (!btn || !res) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="small-spinner"></span> Analizando...';
    const params = new URLSearchParams({
      nombre: pick(props, ['NOMBRE','NOMBRE_SE','NAME'], ''),
      empresa: pick(props, ['EMPRESA'], ''),
      departamento: pick(props, ['DEPARTAMENTO','DEPARTAMEN','REGION','NOMBDEP','DPTO','UBIGEO_DEP'], ''),
    });
    const cap = pick(props, ['CAPACIDAD'], '');
    const pot = pick(props, ['POT_INST'], '');
    if (cap) params.set('capacidad', cap);
    if (pot) params.set('pot_inst', pot);
    try {
      openAnalysisPanel(`SET - ${pick(props, ['NOMBRE','NOMBRE_SE','NAME'], 'Subestación')}`, '<div class="ana-res"><div class="pu-st st-warn"><span class="small-spinner"></span> Analizando disponibilidad, PIT y PT COES...</div></div>', 'Disponibilidad de carga');
      const resp = await fetch(`api/analyze.php?${params.toString()}`);
      const data = await readJsonResponse(resp);
      setAnalysisResults.set(id, data);
      const html = renderSetAnalysis(data, id);
      res.innerHTML = '<div class="ana-res"><div class="pu-st st-ok">Análisis abierto en el panel izquierdo.</div></div>';
      openAnalysisPanel(`SET - ${pick(props, ['NOMBRE','NOMBRE_SE','NAME'], 'Subestación')}`, html, 'Disponibilidad de carga, PIT y PT COES');
      toast(`Disponibilidad: ${pick(props, ['NOMBRE'], 'SET')}`, data.status === 'success' ? 'ok' : 'wa', 4500);
    } catch (err) {
      const html = `<div class="ana-res"><div class="pu-st st-no">${esc(err.message)}</div></div>`;
      res.innerHTML = html;
      openAnalysisPanel('Error de análisis SET', html, 'Disponibilidad de carga');
      toast('No se pudo completar el análisis', 'er');
    } finally {
      btn.disabled = false;
      btn.innerHTML = 'Recalcular disponibilidad';
    }
  }

  function renderLineLoad(data, reportId='') {
    if (data.status === 'error') {
      return `<div class="ana-res"><div class="pu-st st-no">${esc(data.message || data.msg || 'No se pudo leer la carga.')}</div></div>`;
    }
    if (data.status === 'no_data') {
      return `<div class="ana-res"><div class="pu-st st-warn">${esc(data.mensaje || 'Sin datos de carga para esta línea.')}</div></div>`;
    }
    const u = data.operativa || data.ultima || {};
    const latest = data.ultima || {};
    const maxRec = data.maxima_registrada || {};
    const excluded = data.excluidos_recientes || [];
    const excludedRows = excluded.map(r => `
      <div class="line-excluded-row">
        <strong>${esc(r.periodo || r.fecha_maxima_demanda || '')}</strong>
        <span>${f2(r.carga_mva,2)} MVA</span>
        <em>${esc(r.observacion || r.estado_operativo || 'Registro excluido')}</em>
      </div>`).join('');
    const latestMsg = data.alerta
      ? `<div class="line-note">${esc(data.alerta)} Ultimo registro: ${f2(latest.carga_mva,2)} MVA (${esc(latest.observacion || latest.estado_operativo || 'sin observacion')}).</div>`
      : (u.observacion ? `<div class="line-note">${esc(u.observacion)}</div>` : '');
    const maxMsg = data.mensaje_maxima ? `<div class="line-note">${esc(data.mensaje_maxima)}</div>` : '';
    const ceraunicoBox = renderCeraunicoSummary(data.descargas_atmosfericas);
    const reportButtons = reportId ? `<div class="analysis-actions line-actions">
      <button type="button" class="btn-analyze report-btn" data-line-report="${esc(reportId)}" data-format="pdf">Exportar PDF</button>
      <button type="button" class="btn-analyze report-btn" data-line-report="${esc(reportId)}" data-format="docx">Exportar Word</button>
    </div>` : '';
    return `<div class="ana-res line-load">
      <div class="ana-ttl">Carga operativa de línea</div>
      ${reportButtons}
      <div class="line-load-grid">
        <div><span>Carga operativa MVA</span><strong>${f2(u.carga_mva,2)}</strong></div>
        <div><span>MVA nominal</span><strong>${f2(u.mva_nominal,2)}</strong></div>
        <div><span>Uso potencia</span><strong>${f2(u.fac_uso_pot,1)}%</strong></div>
        <div><span>Carga maxima registrada</span><strong>${f2(maxRec.carga_mva,2)} MVA</strong></div>
        <div><span>Fecha maxima registrada</span><strong>${esc(maxRec.fecha_maxima_demanda || maxRec.periodo || '-')}</strong></div>
        <div><span>Fecha operativa</span><strong>${esc(u.fecha_maxima_demanda || u.periodo || '—')}</strong></div>
      </div>
      ${renderLoadChart(data.serie, { title:'Carga MVA registrada' })}
      ${latestMsg}
      ${maxMsg}
      ${excludedRows ? `<div class="line-excluded"><div>Registros recientes excluidos del calculo</div>${excludedRows}</div>` : ''}
      ${ceraunicoBox}
      ${renderPtCoes(data.pt_coes)}
      <div class="analysis-source">Archivo: ${esc(data.archivo || 'Potencia Aparente LLTT_2025')} · Criterio: ${esc(data.criterio || 'Ultimo registro operativo valido')}</div>
    </div>`;
  }

  async function runLineLoad(props) {
    const id = lineAnalysisId(props);
    const btn = document.getElementById(`lb-${id}`);
    const res = document.getElementById(`lr-${id}`);
    if (!btn || !res) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="small-spinner"></span> Leyendo carga...';
    const params = new URLSearchParams({
      codigo: pick(props, ['COD_LINEA','COD_LINEA_1','CODIGO_LINEA','CODIGO'], ''),
      nombre: pick(props, ['NOMBRE','NOMBRE_LINEA','NAME'], ''),
      empresa: pick(props, ['EMPRESA'], ''),
      departamento: pick(props, ['DEPARTAMENTO','DEPARTAMEN','REGION','NOMBDEP','DPTO','UBIGEO_DEP'], ''),
    });
    try {
      openAnalysisPanel(`Línea - ${pick(props, ['COD_LINEA','NOMBRE','NOMBRE_LINEA'], 'LT')}`, '<div class="ana-res"><div class="pu-st st-warn"><span class="small-spinner"></span> Leyendo carga de línea...</div></div>', 'Carga operativa');
      const resp = await fetch(`api/line_load.php?${params.toString()}`);
      const data = await readJsonResponse(resp);
      lineAnalysisResults.set(id, data);
      const html = renderLineLoad(data, id);
      res.innerHTML = '<div class="ana-res"><div class="pu-st st-ok">Carga abierta en el panel izquierdo.</div></div>';
      openAnalysisPanel(`Línea - ${pick(props, ['COD_LINEA','NOMBRE','NOMBRE_LINEA'], 'LT')}`, html, 'Carga operativa de transmisión');
      toast(`Carga línea: ${pick(props, ['COD_LINEA','NOMBRE'], 'LT')}`, data.status === 'success' ? 'ok' : 'wa', 3500);
    } catch (err) {
      const html = `<div class="ana-res"><div class="pu-st st-no">${esc(err.message)}</div></div>`;
      res.innerHTML = html;
      openAnalysisPanel('Error de carga de línea', html, 'Carga operativa');
      toast('No se pudo leer la carga de línea', 'er');
    } finally {
      btn.disabled = false;
      btn.innerHTML = 'Recalcular carga de línea';
    }
  }

  const LINE_SELECT_WEIGHT = 4.8;
  const MT_SELECT_WEIGHT = 2.2;
  const LSTYLES = {
    point: (c, cfg={}, id='') => ({
      pointToLayer:(f,ll)=> isSetLayer(id, cfg)
        ? L.marker(ll, { icon:setVoltageIcon(f.properties || {}, c) })
        : L.circleMarker(ll,{radius:5,fillColor:c,color:'#ffffff',weight:1.5,fillOpacity:.9,opacity:.96}),
    }),
    line: (c, cfg={}, id='') => ({
      interactive: true,
      style:f=> isMtLayerConfig(id, cfg)
        ? {color:lineColor(f.properties || {}, c),weight:MT_SELECT_WEIGHT,opacity:.50,lineCap:'round',lineJoin:'round'}
        : {color:lineColor(f.properties || {}, c),weight:LINE_SELECT_WEIGHT,opacity:.78,lineCap:'round',lineJoin:'round'}
    }),
    polygon: (c, cfg={}, id='') => ({
      ...(id === 'MIDAGRI_Departamentos' ? {interactive:false} : {}),
      style:()=> id === 'MIDAGRI_Departamentos'
        ? {color:'#84cc16',weight:2.2,opacity:.95,fillColor:'#84cc16',fillOpacity:.015,dashArray:'7,5',lineCap:'round',lineJoin:'round'}
        : {color:c,weight:1.6,opacity:.92,fillColor:c,fillOpacity:.10,lineCap:'round',lineJoin:'round'}
    }),
  };

  function isSelectableTransmissionLayer(id, cfg) {
    if (!cfg) return false;
    if (cfg.kind === 'line') return true;
    if (cfg.tipo !== 'line') return false;
    if (isMtLayerConfig(id, cfg) || cfg.kind === 'mt') return false;
    const label = String(cfg.nombre || cfg.name || id || '').toLowerCase();
    return label.includes('lt') || label.includes('transmision') || label.includes('transmisión') || label.includes('linea') || label.includes('línea');
  }
  function raiseSelectableLineLayers() {
    Object.entries(SIG.geoLayers || {}).forEach(([id, layer]) => {
      const cfg = _CAPAS.find(c => c.id === id) || OSI_LAYERS[id] || (_APIS || []).find(a => a.id === id);
      if (isSelectableTransmissionLayer(id, cfg)) layer?.bringToFront?.();
    });
  }

  function renderVoltageLegend(kind, title) {
    const marker = kind === 'set';
    const rows = VOLTAGE_LEGEND.map(v => `
      <div class="leg-it">
        ${marker
          ? `<div class="leg-set"><span style="--set-color:${v.color}"></span></div>`
          : `<div class="leg-line" style="background:${v.color}"></div>`}
        <span>${v.label}</span>
      </div>
    `).join('');
    return `<div class="leg-sec">
      <div class="leg-sub">${esc(title)}</div>
      <div class="leg-note">Tension Nominal (kV)</div>
      ${rows}
    </div>`;
  }
  function renderGenerationLegend() {
    const rows = GENERATION_TYPES.map(t => `
      <div class="leg-it">
        <div class="leg-gen" style="--gen-color:${t.color}"><span>${esc(t.mark)}</span></div>
        <span>${esc(t.label)}</span>
      </div>
    `).join('');
    return `<div class="leg-sec">
      <div class="leg-sub">Centrales de generacion</div>
      <div class="leg-note">Tipo de tecnologia o fuente principal</div>
      ${rows}
    </div>`;
  }
  function renderCeraunicoLegend() {
    const rows = CERAUNICO_CLASSES.map(c => `
      <div class="leg-it"><div class="leg-poly" style="background:${c.color};border-color:#334155"></div><span>${c.label} rayos/km2/año · ${c.risk}</span></div>
    `).join('');
    return `<div class="leg-sec">
      <div class="leg-sub">CERAUNICO - Rayos totales</div>
      <div class="leg-note">Numero total de rayos por km2/año. La cuadricula 1 km2 aparece al acercar el mapa.</div>
      ${rows}
      <div class="leg-src">Fuente: OSINERGMIN, Mapa CERAUNICO del Peru.</div>
    </div>`;
  }

  function renderGenericLegendItem(el) {
    const tipo = el.dataset.tipo || 'polygon';
    const color = el.dataset.color || '#38bdf8';
    const name = el.querySelector('.lyr-name')?.textContent?.trim() || el.dataset.id || 'Capa';
    const symbol = tipo === 'line'
      ? `<div class="leg-line" style="background:${esc(color)}"></div>`
      : tipo === 'point'
        ? `<div class="leg-point" style="background:${esc(color)}"></div>`
        : `<div class="leg-poly" style="background:${esc(color)}"></div>`;
    return `<div class="leg-it">${symbol}<span>${esc(name)}</span></div>`;
  }
  function renderActiveLegend() {
    const body = document.getElementById('legendBody');
    if (!body) return;
    const active = [...document.querySelectorAll('.lyr.on[data-id]')];
    if (!active.length) {
      body.innerHTML = '<div class="leg-empty">Activa una capa para ver su simbologia.</div>';
      return;
    }
    let hasLine = false;
    let hasSet = false;
    let hasGeneration = false;
    let hasCeraunico = false;
    const generic = [];
    const seen = new Set();
    active.forEach(el => {
      const text = normSearchText(`${el.dataset.id || ''} ${el.querySelector('.lyr-name')?.textContent || ''} ${el.querySelector('.lyr-desc')?.textContent || ''}`);
      const tipo = el.dataset.tipo || '';
      if (text.includes('ceraunico') || text.includes('descargas atmosfericas') || text.includes('rayos')) {
        hasCeraunico = true;
        return;
      }
      if (text.includes('generacion') || text.includes('centrales')) {
        hasGeneration = true;
        return;
      }
      if (tipo === 'point' && (/\bset\b/.test(text) || text.includes('subestacion'))) {
        hasSet = true;
        return;
      }
      if (tipo === 'line' && (text.includes('lt') || text.includes('transmision') || text.includes('media tension') || text.includes('tramo'))) {
        hasLine = true;
        return;
      }
      const name = el.querySelector('.lyr-name')?.textContent?.trim() || el.dataset.id || 'Capa';
      const key = `${tipo}|${name}|${el.dataset.color || ''}`;
      if (!seen.has(key)) {
        seen.add(key);
        generic.push(renderGenericLegendItem(el));
      }
    });
    const html = [
      hasLine ? renderVoltageLegend('line', 'Líneas eléctricas') : '',
      hasSet ? renderVoltageLegend('set', 'Subestación transformación (SET)') : '',
      hasGeneration ? renderGenerationLegend() : '',
      hasCeraunico ? renderCeraunicoLegend() : '',
      generic.length ? `<div class="leg-sec"><div class="leg-sub">Otras capas activas</div>${generic.join('')}</div>` : '',
      '<div class="leg-src">Simbología: Mapa energético-minero OSINERGMIN + archivos cargados</div>',
    ].filter(Boolean).join('');
    body.innerHTML = html || '<div class="leg-empty">Activa una capa para ver su simbologia.</div>';
  }

  const CIRA_DEPT = {
    '01':'Amazonas','02':'Ancash','03':'Apurimac','04':'Arequipa','05':'Ayacucho','06':'Cajamarca',
    '07':'Callao','08':'Cusco','09':'Huancavelica','10':'Huanuco','11':'Ica','12':'Junin',
    '13':'La Libertad','14':'Lambayeque','15':'Lima','16':'Loreto','17':'Madre de Dios',
    '18':'Moquegua','19':'Pasco','20':'Piura','21':'Puno','22':'San Martin','23':'Tacna',
    '24':'Tumbes','25':'Ucayali'
  };
  function ciraDept(props={}) {
    const ubigeo = String(props.ubigeo || props.UBIGEO || '').replace(/\D/g, '').padStart(6, '0');
    const code = ubigeo.slice(0, 2);
    return {code, name:props.nomdpto || props.DEPARTAMENTO || CIRA_DEPT[code] || 'Sin departamento'};
  }
  function ciraId(props={}) {
    return String(pick(props, ['idcira','ID_CIRA','id'], '')).replace(/\D/g, '');
  }
  function ciraTitle(props={}) {
    return pick(props, ['nomcira','cira','proyecto'], 'CIRA');
  }
  function ciraPopup(props, exportId='') {
    const id = ciraId(props);
    const detail = id
      ? `<div class="cira-detail-box" id="cira-detail-${esc(id)}"><div class="pu-st st-warn"><span class="small-spinner"></span> Consultando detalle oficial...</div></div>`
      : '<div class="pu-st st-warn">CIRA sin identificador oficial publicado.</div>';
    return `<div>
      <div class="pu-hdr">
        <span class="pu-badge">CIRA (Coordenada Referencial)</span>
        <div class="pu-name">${esc(ciraTitle(props))}</div>
        <div class="pu-emp">${esc([props.nomdpto, props.nomprov, props.nomdist].filter(Boolean).join(' / '))}</div>
      </div>
      <div class="pu-body" style="max-height:360px;overflow-y:auto">
        ${detail}
      </div>
    </div>`;
  }
  function renderCiraDetail(data) {
    if (!data || data.status !== 'success') return `<div class="pu-st st-no">${esc(data?.message || 'No se pudo leer el detalle CIRA.')}</div>`;
    const rows = [
      ['Region', data.region], ['Provincia', data.provincia], ['Distrito', data.distrito],
      ['Zona', data.zona], ['Norte', data.norte], ['Este', data.este],
      ['Nro', data.nro], ['Año', data.anio], ['Fecha de emision', data.fecha_emision],
      ['Expediente', data.expediente], ['Fecha de expediente', data.fecha_expediente],
      ['Proyecto', data.proyecto],
    ].filter(([,v]) => v !== undefined && v !== null && String(v).trim() !== '')
      .map(([k,v]) => `<div class="attr-row"><span class="attr-k">${esc(k)}</span><span class="attr-v">${esc(v)}</span></div>`)
      .join('');
    const pdfParam = String(data.pdf_url || '').match(/[?&]param=([^&#]+)/i)?.[1]?.replace(/[^a-z0-9]/gi, '') || '';
    const pdf = data.local_pdf_url
      ? `<button type="button" class="cira-pdf-btn" data-cira-pdf="${esc(data.id || '')}" data-cira-param="${esc(pdfParam)}">Descargar PDF CIRA</button>`
      : '';
    return `<div class="cira-detail-card">
      <div class="ana-ttl">Detalle oficial CIRA</div>
      ${rows}
      ${pdf || '<div class="pu-st st-warn">Sin PDF publicado por Cultura.</div>'}
    </div>`;
  }
  async function loadCiraDetail(id) {
    const box = document.getElementById(`cira-detail-${id}`);
    if (!box) return;
    if (box.dataset.loaded === '1' || box.dataset.loading === '1') return;
    box.dataset.loading = '1';
    box.innerHTML = '<div class="pu-st st-warn"><span class="small-spinner"></span> Consultando detalle oficial...</div>';
    try {
      const resp = await fetch(`api/cira_detail.php?id=${encodeURIComponent(id)}`);
      const data = await readJsonResponse(resp);
      box.innerHTML = renderCiraDetail(data);
      box.dataset.loaded = data?.status === 'success' ? '1' : '0';
    } catch (err) {
      box.innerHTML = `<div class="pu-st st-no">${esc(err.message)}</div>`;
      box.dataset.loaded = '0';
    } finally {
      box.dataset.loading = '0';
    }
  }
  function createCiraLayer(gj, cfg, color) {
    const group = L.layerGroup();
    const buckets = {};
    let pointsLayer = null;

    pointsLayer = L.geoJSON(gj, {
      pointToLayer:(f,ll)=> L.circleMarker(ll, {
        radius:4.5,
        fillColor:color,
        color:'#ffffff',
        weight:1.2,
        fillOpacity:.86,
        opacity:.95,
      }),
      onEachFeature:(f, layer) => {
        const props = f.properties || {};
        const dept = ciraDept(props);
        props.DEPARTAMENTO = props.DEPARTAMENTO || dept.name;
        const exportId = registerExportFeature(f, ciraTitle(props), 'Ministerio de Cultura');
        layer.bindPopup(ciraPopup(props, exportId), {maxWidth:430});
        layer.on('popupopen', () => {
          const id = ciraId(props);
          if (id) loadCiraDetail(id);
        });
        layer.on({
          mouseover: e => e.target.setStyle?.({radius:7,fillOpacity:1,opacity:1}),
          mouseout: e => pointsLayer?.resetStyle?.(e.target),
        });
        const center = layerCenter(layer);
        addFeatureIndex(props, layer, center, 'point', 'CIRAS aprobados', ciraTitle(props));
      },
    });

    (gj.features || []).forEach(f => {
      const coords = f?.geometry?.coordinates || [];
      const lng = Number(coords[0]);
      const lat = Number(coords[1]);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
      const props = f.properties || {};
      const dept = ciraDept(props);
      const key = dept.code || '00';
      buckets[key] = buckets[key] || {name:dept.name, count:0, lat:0, lng:0};
      buckets[key].count++;
      buckets[key].lat += lat;
      buckets[key].lng += lng;
    });

    const deptLayer = L.layerGroup();
    Object.values(buckets).forEach(b => {
      const lat = b.lat / b.count;
      const lng = b.lng / b.count;
      const radius = Math.max(12, Math.min(34, 8 + Math.sqrt(b.count) * .55));
      const marker = L.circleMarker([lat, lng], {
        radius,
        fillColor:color,
        color:'#ffffff',
        weight:2,
        fillOpacity:.72,
        opacity:.96,
      });
      marker.bindTooltip(String(b.count.toLocaleString('es-PE')), {
        permanent:true,
        direction:'center',
        className:'cira-cluster-label',
      });
      marker.bindPopup(`<div class="pu-hdr"><span class="pu-badge">CIRAS aprobados</span><div class="pu-name">${esc(b.name)}</div><div class="pu-emp">${b.count.toLocaleString('es-PE')} CIRAS registrados</div></div><div class="pu-body"><button class="btn-analyze" type="button" data-cira-zoom data-lat="${lat}" data-lng="${lng}">Ampliar departamento</button></div>`, {maxWidth:300});
      marker.on('click', () => mapInstance.flyTo([lat, lng], Math.max(8, mapInstance.getZoom() + 2), {duration:.8}));
      deptLayer.addLayer(marker);
    });

    const clusterColor = count => count >= 80 ? '#f97316' : (count >= 12 ? '#eab308' : '#75c65b');
    const makeGridLayer = zoom => {
      const cell = zoom < 9 ? 0.55 : (zoom < 10 ? 0.28 : 0.13);
      const grid = {};
      (gj.features || []).forEach(f => {
        const coords = f?.geometry?.coordinates || [];
        const lng = Number(coords[0]);
        const lat = Number(coords[1]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const key = `${Math.floor(lat / cell)}|${Math.floor(lng / cell)}`;
        grid[key] = grid[key] || {count:0, lat:0, lng:0, samples:[]};
        grid[key].count++;
        grid[key].lat += lat;
        grid[key].lng += lng;
        if (grid[key].samples.length < 4) grid[key].samples.push(f.properties || {});
      });
      const layer = L.layerGroup();
      Object.values(grid).forEach(b => {
        const lat = b.lat / b.count;
        const lng = b.lng / b.count;
        const fill = clusterColor(b.count);
        const radius = Math.max(10, Math.min(28, 7 + Math.sqrt(b.count) * 1.2));
        const marker = L.circleMarker([lat, lng], {
          radius,
          fillColor:fill,
          color:'#fef08a',
          weight:2,
          fillOpacity:.76,
          opacity:.95,
        });
        marker.bindTooltip(String(b.count.toLocaleString('es-PE')), {
          permanent:true,
          direction:'center',
          className:'cira-cluster-label',
        });
        const names = b.samples.map(s => ciraTitle(s)).filter(Boolean).join('<br>');
        marker.bindPopup(`<div class="pu-hdr"><span class="pu-badge">CIRAS aprobados</span><div class="pu-name">${b.count.toLocaleString('es-PE')} CIRAS cercanos</div></div><div class="pu-body">${names ? `<small>${names}</small>` : ''}<button class="btn-analyze" type="button" data-cira-zoom data-lat="${lat}" data-lng="${lng}">Ampliar zona</button></div>`, {maxWidth:300});
        marker.on('click', () => mapInstance.flyTo([lat, lng], Math.max(zoom + 2, 10), {duration:.8}));
        layer.addLayer(marker);
      });
      return layer;
    };

    const sync = () => {
      group.clearLayers();
      const zoom = mapInstance.getZoom();
      if (zoom < 7) group.addLayer(deptLayer);
      else if (zoom < 12) group.addLayer(makeGridLayer(zoom));
      else group.addLayer(pointsLayer);
    };
    group.on('add', () => {
      sync();
      mapInstance.on('zoomend', sync);
    });
    group.on('remove', () => {
      mapInstance.off('zoomend', sync);
      group.clearLayers();
    });
    group.getBounds = () => pointsLayer.getBounds();
    group.__ciraPoints = pointsLayer;
    group.__ciraDepartments = deptLayer;
    return group;
  }

  async function loadGeoLayer(id, tipo, color) {
    if (SIG.geoLayers[id]) return SIG.geoLayers[id];
    toast(`Cargando capa: ${id}…`, 'in', 3000);
    try {
      const resp = await fetch(`api/get_layer.php?id=${encodeURIComponent(id)}`);
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const gj   = await resp.json();
      const cfg = _CAPAS.find(c => c.id === id) || { nombre:id };
      const opts = {
        ...(LSTYLES[tipo]?.(color, cfg, id) || {}),
        onEachFeature:(f,layer) => {
          const props = f.properties || {};
          if (id === 'MIDAGRI_Departamentos') {
            layer.on({
              mouseover: e => e.target.setStyle?.({weight:3.1,opacity:1,fillOpacity:.025}),
              mouseout: e => SIG.geoLayers[id]?.resetStyle?.(e.target),
            });
            const center = layerCenter(layer);
            addFeatureIndex(props, layer, center, tipo, cfg.nombre || id, cfg.nombre || id);
            return;
          }
          const exportId = registerExportFeature(f, cfg.nombre || id, props.FUENTE || 'Datos locales');
          layer.bindPopup(featurePopup(props, cfg.nombre || id, props.FUENTE || 'Datos locales', exportId), {maxWidth:330});
          if (pick(props, ['COD_LINEA'], '')) {
            layer.on('popupopen', () => {
              const btn = document.getElementById(`lb-${lineAnalysisId(props)}`);
              if (btn) btn.addEventListener('click', ev => { ev.preventDefault(); runLineLoad(props); }, {once:false});
            });
          }
          if (isSetFeature(props, cfg.nombre || id)) {
            layer.on('popupopen', () => {
              const btn = document.getElementById(`ab-${setAnalysisId(props)}`);
              if (btn) btn.addEventListener('click', ev => { ev.preventDefault(); runSetAvailability(props); }, {once:false});
            });
          }
          layer.on({
            mouseover: e => e.target.setStyle?.({weight:2.6,fillOpacity:.18,opacity:1}),
            mouseout: e => SIG.geoLayers[id]?.resetStyle?.(e.target),
          });
          const center = layerCenter(layer);
          addFeatureIndex(props, layer, center, tipo, cfg.nombre || id, cfg.nombre || id);
        },
      };
      const layer = L.geoJSON(gj, opts);
      if (tipo === 'line') layer.on('add', () => layer.bringToFront?.());
      if (id === 'MIDAGRI_Departamentos') layer.on('add', () => layer.bringToFront?.());
      SIG.geoLayers[id] = layer;
      toast(`Cargada: ${id} (${gj.features?.length||0} elementos)`, 'ok', 2500);
      return layer;
    } catch (err) {
      toast(`Error cargando ${id}: ${err.message}`, 'er');
      return null;
    }
  }

  async function loadExternalLayer(id, tipo, color) {
    if (SIG.geoLayers[id]) return SIG.geoLayers[id];
    const cfg = (_APIS || []).find(a => a.id === id) || { name:id, tipo, color };
    toast(`Cargando API externa: ${cfg.name || id}...`, 'in', 3500);
    try {
      const resp = await fetch(`api/external_layer.php?id=${encodeURIComponent(id)}`);
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const gj = await resp.json();
      if (gj.error) throw new Error(gj.error);
      if (cfg.id === 'cultura_cira_aprobados') {
        const layer = createCiraLayer(gj, cfg, color);
        SIG.geoLayers[id] = layer;
        toast(`CIRAS cargados: ${(gj.features?.length || 0).toLocaleString('es-PE')} registros`, 'ok', 3000);
        return layer;
      }
      const opts = {
        ...(LSTYLES[tipo]?.(color, cfg, id) || LSTYLES.point(color, cfg, id)),
        onEachFeature:(f, layer) => {
          const props = f.properties || {};
          const sourceLabel = cfg.id === 'cultura_cira_aprobados' ? 'Ministerio de Cultura' : 'API externa';
          const exportId = registerExportFeature(f, cfg.name || id, sourceLabel);
          layer.bindPopup(featurePopup(props, cfg.name || id, sourceLabel, exportId), {maxWidth:420});
          layer.on({
            mouseover: e => e.target.setStyle?.({weight:3,fillOpacity:.18,opacity:1}),
            mouseout: e => SIG.geoLayers[id]?.resetStyle?.(e.target),
          });
          const center = layerCenter(layer);
          addFeatureIndex(props, layer, center, tipo, `API externa: ${cfg.name || id}`, cfg.name || id);
        },
      };
      const layer = L.geoJSON(gj, opts);
      SIG.geoLayers[id] = layer;
      toast(`API cargada: ${cfg.name || id} (${(gj.features?.length || 0).toLocaleString('es-PE')})`, 'ok', 3000);
      return layer;
    } catch (err) {
      toast(`Error API externa: ${err.message}`, 'er', 6000);
      return null;
    }
  }

  function mtBboxParam() {
    const b = mapInstance.getBounds().pad(0.08);
    const snap = 0.025;
    const west = Math.floor(b.getWest() / snap) * snap;
    const south = Math.floor(b.getSouth() / snap) * snap;
    const east = Math.ceil(b.getEast() / snap) * snap;
    const north = Math.ceil(b.getNorth() / snap) * snap;
    return [
      west.toFixed(5),
      south.toFixed(5),
      east.toFixed(5),
      north.toFixed(5),
    ].join(',');
  }

  function layerOpacityValue(id, fallback=.72) {
    const input = document.querySelector(`.lyr-op input[data-id="${id}"]`);
    const value = Number(input?.value);
    return Number.isFinite(value) ? value : fallback;
  }

  const CeraunicoTileLayer = L.TileLayer.extend({
    initialize:function(cfg={}, options={}) {
      L.setOptions(this, options);
      this.cfg = cfg;
    },
    createTile:function(coords, done) {
      const tile = document.createElement('img');
      tile.alt = '';
      tile.decoding = 'async';
      tile.setAttribute('role', 'presentation');
      const map = this._map || mapInstance;
      const size = this.getTileSize();
      const nwPoint = coords.scaleBy(size);
      const sePoint = nwPoint.add(size);
      const nw = map.unproject(nwPoint, coords.z);
      const se = map.unproject(sePoint, coords.z);
      const bounds = L.latLngBounds(se, nw);
      const crs = map.options.crs || L.CRS.EPSG3857;
      const sw = crs.project(bounds.getSouthWest());
      const ne = crs.project(bounds.getNorthEast());
      const year = Number(this.cfg?.year || 2025);
      const rasterLayer = CERAUNICO_YEAR_LAYERS[year] || CERAUNICO_YEAR_LAYERS[2025];
      const overlayLayers = [rasterLayer, CERAUNICO_GRID_LAYER, CERAUNICO_BOUNDARY_LAYER];
      const params = new URLSearchParams({
        bbox:[sw.x, sw.y, ne.x, ne.y].map(v => v.toFixed(3)).join(','),
        bboxSR:'3857',
        imageSR:'3857',
        size:`${size.x},${size.y}`,
        format:'png32',
        transparent:'true',
        layers:`show:${overlayLayers.join(',')}`,
        f:'image',
      });
      tile.onload = () => done(null, tile);
      tile.onerror = () => done(new Error('No se pudo cargar CERAUNICO'), tile);
      tile.src = `${CERAUNICO_REST}/export?${params.toString()}`;
      return tile;
    },
  });

  async function loadCeraunicoLayer(id, cfg) {
    if (SIG.geoLayers[id]) return SIG.geoLayers[id];
    const layer = new CeraunicoTileLayer(cfg, {
      tileSize:256,
      opacity:layerOpacityValue(id, .90),
      minZoom:4,
      maxZoom:18,
      updateWhenIdle:true,
      updateWhenZooming:false,
      keepBuffer:1,
      zIndex:215,
      attribution:'OSINERGMIN CERAUNICO',
    });
    layer.on('add', () => {
      toast('CERAUNICO activado en modo mosaico alineado.', 'ok', 2800);
    });
    layer.on('tileerror', () => {
      toast('No se pudo cargar un mosaico de CERAUNICO desde OSINERGMIN.', 'er', 4200);
    });
    SIG.geoLayers[id] = layer;
    return layer;
  }

  async function loadOsinergminMtLayer(id, cfg) {
    if (SIG.geoLayers[id]) return SIG.geoLayers[id];
    const group = L.layerGroup();
    let seq = 0;
    let lastKey = '';
    let hinted = false;
    let currentLayer = null;
    const sync = async () => {
      if (!mapInstance.hasLayer(group)) return;
      const zoom = mapInstance.getZoom();
      if (zoom < 12) {
        group.clearLayers();
        currentLayer = null;
        lastKey = '';
        if (!hinted) {
          hinted = true;
          toast('MT activada en modo liviano: acercate mas al area de trabajo para cargar solo tramos visibles.', 'in', 5200);
        }
        return;
      }
      const bbox = mtBboxParam();
      const limit = zoom >= 14 ? 1500 : 900;
      const key = `${Math.floor(zoom)}|${bbox}|${limit}`;
      if (key === lastKey) return;
      lastKey = key;
      const mySeq = ++seq;
      toast('Cargando MT visible...', 'in', 1600);
      try {
        const resp = await fetch(`api/osinergmin_layer.php?layer=${encodeURIComponent(cfg.layer)}&bbox=${encodeURIComponent(bbox)}&limit=${limit}`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const gj = await resp.json();
        if (gj.error) throw new Error(gj.error);
        if (mySeq !== seq) return;
        const mtRenderer = L.canvas ? L.canvas({padding:.22, tolerance:14}) : undefined;
        const layer = L.geoJSON(gj, {
          renderer: mtRenderer,
          style:f => ({
            color: lineColor(f.properties || {}, cfg.color),
            weight: zoom >= 14 ? 1.45 : 1,
            opacity: zoom >= 14 ? .66 : .38,
            lineCap:'round',
            lineJoin:'round',
          }),
          onEachFeature:(f, layer) => {
            const props = f.properties || {};
            const exportId = registerExportFeature(f, cfg.nombre, 'OSINERGMIN');
            layer.bindPopup(mtPopup(props, cfg.nombre, 'OSINERGMIN', exportId), {maxWidth:520});
            layer.on({
              mouseover: e => e.target.setStyle?.({weight:2.6,opacity:1}),
              mouseout: e => currentLayer?.resetStyle?.(e.target),
            });
          },
        });
        group.clearLayers();
        currentLayer = layer;
        group.addLayer(layer);
        const count = gj.features?.length || 0;
        toast(`MT visible: ${count.toLocaleString('es-PE')} tramos${count >= limit ? ' (acercate para ver mas detalle)' : ''}`, 'ok', 1800);
      } catch (err) {
        toast(`Error MT: ${err.message}`, 'er', 4200);
      }
    };
    group.on('add', () => {
      sync();
      mapInstance.on('moveend zoomend', sync);
    });
    group.on('remove', () => {
      mapInstance.off('moveend zoomend', sync);
      group.clearLayers();
      currentLayer = null;
    });
    group.getBounds = () => currentLayer?.getBounds?.() || mapInstance.getBounds();
    SIG.geoLayers[id] = group;
    return group;
  }

  async function loadOsinergminLayer(id, cfg) {
    if (SIG.geoLayers[id]) return SIG.geoLayers[id];
    if (cfg?.kind === 'ceraunico') return loadCeraunicoLayer(id, cfg);
    if (cfg?.kind === 'mt') return loadOsinergminMtLayer(id, cfg);
    toast(`Conectando OSINERGMIN: ${cfg.nombre}…`, 'in', 3500);
    try {
      const resp = await fetch(`api/osinergmin_layer.php?layer=${encodeURIComponent(cfg.layer)}`);
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const gj = await resp.json();
      if (gj.error) throw new Error(gj.error);

      const opts = {
        pointToLayer:(f,ll)=> cfg.kind === 'set'
          ? L.marker(ll, { icon:setVoltageIcon(f.properties || {}, cfg.color) })
          : cfg.kind === 'generation'
            ? L.marker(ll, { icon:generationIcon(f.properties || {}) })
          : L.circleMarker(ll, {
              radius: 4,
              fillColor: cfg.color,
              color: '#ffffff',
              weight: 1.4,
              fillOpacity: .92,
              opacity: .95,
            }),
        style:(f)=> cfg.tipo === 'polygon'
          ? { color:cfg.color, weight:1.6, opacity:.92, fillColor:cfg.color, fillOpacity:.10, lineCap:'round', lineJoin:'round' }
          : { color:lineColor(f.properties || {}, cfg.color), weight:LINE_SELECT_WEIGHT, opacity:.80, lineCap:'round', lineJoin:'round' },
        onEachFeature:(f, layer) => {
          const props = f.properties || {};
          const exportId = registerExportFeature(f, cfg.nombre, 'OSINERGMIN');
          layer.bindPopup(
            cfg.kind === 'set' ? officialSetPopup(props, cfg)
              : cfg.kind === 'generation' ? generationPopup(props, cfg, exportId)
              : cfg.kind === 'line' ? officialLinePopup(props, cfg, exportId)
              : featurePopup(props, cfg.nombre, 'OSINERGMIN', exportId),
            {maxWidth:520}
          );
          if (cfg.kind === 'set') {
            layer.on('popupopen', () => {
              const btn = document.getElementById(`ab-${setAnalysisId(props)}`);
              if (btn) btn.addEventListener('click', ev => { ev.preventDefault(); runSetAvailability(props); }, {once:false});
            });
          } else if (cfg.kind === 'line') {
            layer.on('popupopen', () => {
              const btn = document.getElementById(`lb-${lineAnalysisId(props)}`);
              if (btn) btn.addEventListener('click', ev => { ev.preventDefault(); runLineLoad(props); }, {once:false});
            });
          }
          layer.on({
            mouseover: e => e.target.setStyle?.({weight:3.4,fillOpacity:.18,opacity:1}),
            mouseout: e => SIG.geoLayers[id]?.resetStyle?.(e.target),
          });
          const center = layerCenter(layer);
          addFeatureIndex(props, layer, center, cfg.kind, cfg.nombre, cfg.nombre);
        },
      };
      const layer = L.geoJSON(gj, opts);
      if (cfg.tipo === 'line') layer.on('add', () => layer.bringToFront?.());
      SIG.geoLayers[id] = layer;
      updateMiniStats(cfg.metric, gj.features?.length || 0);
      toast(`OSINERGMIN cargado: ${cfg.nombre} (${(gj.features?.length||0).toLocaleString('es-PE')})`, 'ok', 2800);
      return layer;
    } catch (err) {
      toast(`Error OSINERGMIN: ${err.message}`, 'er', 5000);
      return null;
    }
  }

  document.querySelectorAll('.sb-menu-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.layerMenu;
      document.querySelectorAll('.sb-menu-tab').forEach(x => x.classList.toggle('active', x === btn));
      document.querySelectorAll('.sb-menu-panel').forEach(panel => {
        panel.classList.toggle('active', panel.dataset.layerMenuPanel === key);
      });
    });
  });

  /* Eventos de capa en sidebar */
  document.querySelectorAll('.lyr[data-id]').forEach(el => {
    const id    = el.dataset.id;
    const tipo  = el.dataset.tipo  || 'point';
    const color = el.dataset.color || '#00d4ff';
    const chk   = el.querySelector('.lyr-chk');
    const provider = el.dataset.provider || '';
    const official = provider === 'osinergmin';
    const external = provider === 'api';
    const osCfg = OSI_LAYERS[id];

    /* Cargar capas activas al inicio */
    const conf = _CAPAS.find(c => c.id === id);
    if (conf?.activa || el.classList.contains('on')) {
      const loader = official ? loadOsinergminLayer(id, osCfg) : (external ? loadExternalLayer(id, tipo, color) : loadGeoLayer(id, tipo, color));
      loader.then(l => { if(l) { l.addTo(mapInstance); raiseSelectableLineLayers(); } renderActiveLegend(); });
    }

    el.addEventListener('click', async () => {
      if (el.classList.contains('on')) {
        SIG.geoLayers[id]?.remove();
        el.classList.remove('on'); chk.textContent = '';
        updateMiniStats(null, 0);
        renderActiveLegend();
      } else {
        const l = official ? await loadOsinergminLayer(id, osCfg) : (external ? await loadExternalLayer(id, tipo, color) : await loadGeoLayer(id, tipo, color));
        if (l) { l.addTo(mapInstance); raiseSelectableLineLayers(); el.classList.add('on'); chk.textContent = '\u2713'; }
        updateMiniStats(null, 0);
        renderActiveLegend();
      }
    });
  });

  /* Sliders opacidad */
  document.querySelectorAll('.lyr-op input[type=range]').forEach(sl => {
    const sp = sl.nextElementSibling;
    sl.addEventListener('input', function () {
      sp.textContent = Math.round(this.value*100)+'%';
      const l = SIG.geoLayers[this.dataset.id];
      if (!l) return;
      if (l.setOpacity && !l.eachLayer) {
        l.setOpacity(+this.value);
        return;
      }
      l.eachLayer(sub => {
        sub.setStyle && sub.setStyle({opacity:+this.value, fillOpacity:+this.value*.12});
        sub.setOpacity && sub.setOpacity(+this.value);
      });
    });
  });

  /* Busqueda con sugerencias */
  const searchInput = document.getElementById('searchInput');
  const searchSuggest = document.getElementById('searchSuggest');
  let searchResults = [];
  let searchActive = -1;

  function scoreSearchHit(hit, query) {
    const title = normSearchText(hit.title || '');
    const subtitle = normSearchText(hit.subtitle || '');
    let score = 0;
    if (title === query) score += 120;
    else if (title.startsWith(query)) score += 92;
    else if (title.includes(query)) score += 72;
    if (subtitle.includes(query)) score += 36;
    if (hit.text.includes(query)) score += 24;
    const words = query.split(' ').filter(Boolean);
    if (words.length > 1 && words.every(w => hit.text.includes(w))) score += 28;
    if (hit.label === 'SET') score += 6;
    if (hit.label === 'Concesión') score += 5;
    return score;
  }
  function getSearchResults(query) {
    const q = normSearchText(query);
    if (q.length < 2) return [];
    return SIG.featureIndex
      .map(hit => ({ hit, score:scoreSearchHit(hit, q) }))
      .filter(x => x.score > 0)
      .sort((a, b) => b.score - a.score || String(a.hit.title).localeCompare(String(b.hit.title)))
      .slice(0, 10)
      .map(x => x.hit);
  }
  function hideSearchSuggest() {
    if (!searchSuggest) return;
    searchSuggest.classList.remove('open');
    searchSuggest.innerHTML = '';
    searchResults = [];
    searchActive = -1;
  }
  function renderSearchSuggest() {
    if (!searchInput || !searchSuggest) return;
    const q = searchInput.value.trim();
    if (q.length < 2) return hideSearchSuggest();
    searchResults = getSearchResults(q);
    searchActive = searchResults.length ? 0 : -1;
    if (!SIG.featureIndex.length) {
      searchSuggest.innerHTML = '<div class="search-empty">Activa o carga una capa para buscar elementos.</div>';
      searchSuggest.classList.add('open');
      return;
    }
    if (!searchResults.length) {
      searchSuggest.innerHTML = '<div class="search-empty">Sin coincidencias cercanas.</div>';
      searchSuggest.classList.add('open');
      return;
    }
    searchSuggest.innerHTML = searchResults.map((hit, idx) => `
      <button type="button" class="search-option ${idx === searchActive ? 'active' : ''}" data-search-idx="${idx}" role="option">
        <span class="search-tag">${esc(hit.label || 'Capa')}</span>
        <span class="search-name">${esc(hit.title || 'Elemento')}</span>
        <span class="search-go">&rsaquo;</span>
        <span class="search-meta">${esc(hit.subtitle || hit.source || '')}</span>
      </button>
    `).join('');
    searchSuggest.classList.add('open');
  }
  function refreshActiveSearchOption() {
    if (!searchSuggest) return;
    searchSuggest.querySelectorAll('.search-option').forEach((el, idx) => {
      el.classList.toggle('active', idx === searchActive);
    });
  }
  function focusSearchHit(hit) {
    if (!hit) return;
    const zoom = hit.kind === 'line' ? 10 : (hit.kind === 'polygon' ? 11 : 13);
    mapInstance.flyTo(hit.latlng, zoom, {duration:1.1});
    setTimeout(() => hit.layer?.openPopup?.(hit.latlng), 900);
    searchInput.value = '';
    hideSearchSuggest();
  }
  function lineDistancePx(layer, point) {
    const parts = Array.isArray(layer?._parts) ? layer._parts : [];
    let best = Infinity;
    for (const part of parts) {
      if (!Array.isArray(part) || part.length < 2) continue;
      for (let i = 1; i < part.length; i++) {
        const distance = L.LineUtil.pointToSegmentDistance(point, part[i - 1], part[i]);
        if (distance < best) best = distance;
      }
    }
    return best;
  }
  function nearestVisibleTransmissionLine(latlng, tolerancePx=14) {
    const point = mapInstance.latLngToLayerPoint(latlng);
    let selected = null;
    let best = tolerancePx + 0.001;
    for (const hit of SIG.featureIndex || []) {
      if (hit.kind !== 'line' || hit.layer?._map !== mapInstance || !hit.layer?.getPopup?.()) continue;
      const distance = lineDistancePx(hit.layer, point);
      if (distance <= best) {
        selected = hit;
        best = distance;
      }
    }
    return selected;
  }
  function selectNearbyTransmissionLine(event) {
    if (!event?.latlng || event.originalEvent?._cvcLineHandled) return false;
    if (zoneState.drawing || document.body.classList.contains('geo-drawing')) return false;
    const hit = nearestVisibleTransmissionLine(event.latlng);
    if (!hit) return false;
    hit.layer.openPopup(event.latlng);
    return true;
  }
  searchInput?.addEventListener('input', renderSearchSuggest);
  searchInput?.addEventListener('focus', renderSearchSuggest);
  searchInput?.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') return hideSearchSuggest();
    if (e.key === 'ArrowDown' && searchResults.length) {
      e.preventDefault();
      searchActive = (searchActive + 1) % searchResults.length;
      refreshActiveSearchOption();
      return;
    }
    if (e.key === 'ArrowUp' && searchResults.length) {
      e.preventDefault();
      searchActive = (searchActive - 1 + searchResults.length) % searchResults.length;
      refreshActiveSearchOption();
      return;
    }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const hit = searchResults[searchActive] || getSearchResults(this.value)[0];
    if (hit) focusSearchHit(hit);
    else toast('Sin resultados para: ' + this.value.trim(), 'wa');
  });
  searchSuggest?.addEventListener('mousedown', e => {
    const btn = e.target.closest('.search-option');
    if (!btn) return;
    e.preventDefault();
    focusSearchHit(searchResults[Number(btn.dataset.searchIdx)]);
  });
  document.addEventListener('mousedown', e => {
    if (!searchInput?.closest('.srch-box')?.contains(e.target)) hideSearchSuggest();
  });

  /* Cerrar sidebar en móvil al clic en mapa */
  mapInstance.on('click', event => {
    selectNearbyTransmissionLine(event);
    if (window.innerWidth<=768) document.getElementById('sb').classList.remove('open');
  });

  updateMiniStats(null, 0);
  renderActiveLegend();
  startOpportunityScout();
  toast('Sistema cargado con capas oficiales de OSINERGMIN', 'ok', 5000);
}

/* ====================================================================
   INICIO: si ya está autenticado, inicializar
   el mapa directamente (sin secuencia cinemática)
==================================================================== */
if (typeof _AUTH !== 'undefined' && _AUTH === true) {
  /* El mapa ya es visible (PHP lo renderizó con class="visible") */
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(initMap, 200);
  });
}
