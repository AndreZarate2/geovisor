<?php
/**
 * index.php — SIG Energético-Minero OSINERGMIN
 * Archivo único: maneja login Y mapa en el mismo archivo.
 * Elimina problemas de sesión/redirect en hosting compartido.
 */
require_once __DIR__ . '/config.php';

$isAuth   = isAuth();
$username = $_SESSION['username'] ?? 'admin';
$role     = $_SESSION['role']     ?? 'viewer';
$manifest = getManifest();
$capas    = getLocalLayerRegistry(true);
$apis     = getApiRegistry(true);

function sidebarAscii(string $text): string {
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') $text = $converted;
    }
    return strtolower($text);
}
function sidebarSlug(string $text): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', sidebarAscii($text)), '-') ?: 'menu';
}
function sidebarCanonicalMenu(string $menu): string {
    $menu = trim($menu);
    if ($menu === '') return '';
    $s = sidebarAscii($menu);
    return match ($s) {
        'electricidad', 'electrico' => 'Electricidad',
        'agricola y acuicultura' => 'Agricola y Acuicultura',
        'predios' => 'Predios',
        'ciras aprobados' => 'CIRAS aprobados',
        'zonas arqueologicas' => 'Zonas Arqueologicas',
        'ambiental' => 'Ambiental',
        'otros' => 'Otros',
        default => $menu,
    };
}
function sidebarMenuName(string $menu, string $group = '', string $id = ''): string {
    $explicit = trim($menu);
    if ($explicit !== '') return sidebarCanonicalMenu($explicit);
    $s = sidebarAscii($menu . ' ' . $group . ' ' . $id);
    if (str_contains($s, 'ambient') || str_contains($s, 'anp') || str_contains($s, 'ecosistema') || str_contains($s, 'fragil')) return 'Ambiental';
    if (str_contains($s, 'agric') || str_contains($s, 'acui') || str_contains($s, 'midagri') || str_contains($s, 'produce')) return 'Agricola y Acuicultura';
    if (str_contains($s, 'predio') || str_contains($s, 'sunarp') || str_contains($s, 'piura') || str_contains($s, 'olmos') || str_contains($s, 'moquegua')) return 'Predios';
    if (str_contains($s, 'cira') || str_contains($s, 'mc_cira_ubigeo')) return 'CIRAS aprobados';
    if (str_contains($s, 'arque') || str_contains($s, 'cultura')) return 'Zonas Arqueologicas';
    return 'Electricidad';
}
function sidebarPlacementForLayer(array $layer): array {
    $id = (string)($layer['id'] ?? '');
    $group = (string)($layer['grupo'] ?? 'Otras capas');
    $name = (string)($layer['nombre'] ?? $id);
    $menu = sidebarMenuName((string)($layer['menu'] ?? ''), $group, $id . ' ' . $name);
    if (trim((string)($layer['menu'] ?? '')) !== '' && trim($group) !== '') {
        return [$menu, trim($group), 'Capas'];
    }
    $s = sidebarAscii($id . ' ' . $group . ' ' . $name);
    if ($menu === 'Agricola y Acuicultura') {
        if (str_contains($s, 'produce')) return [$menu, 'PRODUCE', 'Archivos acuicolas'];
        if (str_contains($s, 'midagri') || str_contains($s, 'departamento')) return [$menu, 'MIDAGRI', 'Departamentos'];
        return [$menu, 'MIDAGRI', 'Capas agrícolas'];
    }
    if ($menu === 'Predios') return [$menu, 'APIs de predios', 'Capas'];
    if ($menu === 'Zonas Arqueologicas') return [$menu, 'CULTURA', 'Capas arqueologicas'];
    if (str_contains($s, 'conces')) {
        if (str_contains($s, 'tramite')) return [$menu, 'Áreas de concesión', 'En trámite'];
        if (str_contains($s, 'vigente')) return [$menu, 'Áreas de concesión', 'Vigentes'];
        return [$menu, 'Áreas de concesión', 'Capas'];
    }
    if (str_contains($s, 'distrib') || str_contains($s, 'media') || str_contains($s, 'sed') || str_contains($s, 'salida')) return [$menu, 'Distribución', 'Capas'];
    if (str_contains($s, 'subest') || str_contains($s, 'set')) return [$menu, 'Subestaciones', 'Capas'];
    if (str_contains($s, 'trans') || str_contains($s, 'lt')) return [$menu, 'Transmisión', 'Capas'];
    return [$menu, 'Capas', 'Otras capas'];
}
function sidebarPlacementForApi(array $api): array {
    $rawMenu = trim((string)($api['menu'] ?? ''));
    $rawGroup = trim((string)($api['grupo'] ?? ''));
    if ($rawMenu !== '' && $rawGroup !== '') {
        return [sidebarCanonicalMenu($rawMenu), $rawGroup, 'Capas'];
    }
    $text = sidebarAscii(($api['name'] ?? '') . ' ' . ($api['grupo'] ?? '') . ' ' . ($api['menu'] ?? '') . ' ' . ($api['id'] ?? ''));
    if (str_contains($text, 'ambient') || str_contains($text, 'anp') || str_contains($text, 'ecosistema') || str_contains($text, 'fragil')) return ['Ambiental', 'ANP y ecosistemas fragiles', 'Capas'];
    if (str_contains($text, 'cira') || str_contains($text, 'mc_cira_ubigeo')) return ['CIRAS aprobados', 'CIRA - Coordenada referencial', 'CIRAS aprobados'];
    if (str_contains($text, 'pred') || str_contains($text, 'piura') || str_contains($text, 'olmos') || str_contains($text, 'moquegua') || str_contains($text, 'lambayeque')) return ['Predios', 'APIs de predios', 'Moquegua, Olmos, Piura'];
    if (str_contains($text, 'midagri') || str_contains($text, 'agric')) return ['Agricola y Acuicultura', 'MIDAGRI', 'API MIDAGRI'];
    if (str_contains($text, 'produce') || str_contains($text, 'acuic')) return ['Agricola y Acuicultura', 'PRODUCE', 'API PRODUCE'];
    return ['Predios', 'APIs de predios', 'Otras APIs'];
}
function sidebarAdd(array &$tree, string $menu, string $group, string $subgroup, string $kind, array $item): void {
    $tree[$menu][$group][$subgroup][$kind][] = $item;
}
$sidebarTree = [];
foreach ($capas as $c) {
    [$menu, $group, $subgroup] = sidebarPlacementForLayer($c);
    sidebarAdd($sidebarTree, $menu, $group, $subgroup, 'local', $c);
}
foreach ($apis as $api) {
    [$menu, $group, $subgroup] = sidebarPlacementForApi($api);
    sidebarAdd($sidebarTree, $menu, $group, $subgroup, 'api', $api);
}
$sidebarOrder = ['Electricidad','Ambiental','Agricola y Acuicultura','Predios','CIRAS aprobados','Zonas Arqueologicas'];
foreach ($sidebarOrder as $name) $sidebarTree[$name] = $sidebarTree[$name] ?? [];
uksort($sidebarTree, function($a, $b) use ($sidebarOrder) {
    $pa = array_search($a, $sidebarOrder, true);
    $pb = array_search($b, $sidebarOrder, true);
    $pa = $pa === false ? 99 : $pa;
    $pb = $pb === false ? 99 : $pb;
    return $pa === $pb ? strcmp($a, $b) : ($pa <=> $pb);
});
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0"/>
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"/>
<title>GeoVisor CVC Energía</title>
<link rel="stylesheet" href="assets/vendor/leaflet.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(ROOT . '/assets/css/style.css') ?>"/>
<link rel="stylesheet" href="assets/css/geo-workspace.css?v=<?= is_file(ROOT . '/assets/css/geo-workspace.css') ? filemtime(ROOT . '/assets/css/geo-workspace.css') : time() ?>"/>
</head>
<body data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">

<!-- ═══════════════════════════════════════
     CANVAS THREE.JS (fondo siempre visible)
═══════════════════════════════════════ -->
<canvas id="earth-canvas"></canvas>

<!-- ═══════════════════════════════════════
     PANTALLA DE LOGIN (visible si no auth)
═══════════════════════════════════════ -->
<div id="login-screen" style="display:<?= $isAuth ? 'none' : 'flex' ?>">
  <div id="login-card">
    <div class="lc-logo">
      <img class="cvc-logo lc-logo-img" src="assets/img/cvc-logo.png" alt="CVC Energía"/>
      <h1>GeoVisor Eléctrico</h1>
      <p>CVC Energía · OSINERGMIN · Perú</p>
    </div>
    <div class="lc-divider"></div>
    <form id="loginForm" autocomplete="off">
      <?= csrfField() ?>
      <div class="lc-field">
        <label>Usuario</label>
        <div class="lc-inp">
          <span class="lc-ico">&#128100;</span>
          <input type="text" id="usu" name="usuario" placeholder="Ingrese su usuario" required autocomplete="username"/>
        </div>
      </div>
      <div class="lc-field">
        <label>Contraseña</label>
        <div class="lc-inp">
          <span class="lc-ico">&#128274;</span>
          <input type="password" id="pw" name="clave" placeholder="Ingrese su contraseña" required autocomplete="current-password"/>
          <button type="button" class="lc-tpw" id="tpwBtn" aria-label="Ver contraseña" title="Ver contraseña">&#128065;&#65039;</button>
        </div>
      </div>
      <button type="submit" class="lc-btn" id="loginBtn">
        <div class="lc-spin" id="loginSpin"></div>
        <span id="loginTxt">&#128273; &nbsp;Ingresar al sistema</span>
      </button>
      <button type="button" class="lc-btn lc-btn-guest" id="guestLoginBtn">
        <span id="guestLoginTxt">&#128065;&#65039; &nbsp;Ingresar como invitado</span>
      </button>
    </form>
    <div id="loginErr" style="display:none;margin-top:14px;color:#fca5a5;font-size:12px;text-align:center"></div>
    <div class="lc-foot">
      Acceso restringido · Solo personal autorizado<br/>
      <span>CVC Energía</span> · Visualizador geográfico eléctrico
    </div>
  </div>
  <div class="login-visual" aria-hidden="true">
    <div class="login-photo login-photo-a"></div>
    <div class="login-photo login-photo-b"></div>
    <div class="login-visual-glow"></div>
    <div class="login-visual-rings"></div>
    <div class="login-visual-caption">
      <span>CVC Energía</span>
      <strong>Infraestructura eléctrica para decisiones territoriales</strong>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     PANTALLAS CINEMÁTICAS
═══════════════════════════════════════ -->
<div class="cin-overlay" id="cin-welcome">
  <div class="cin-box">
    <div class="cin-pre">✦ Acceso autorizado ✦</div>
    <div class="cin-title" id="cinTitle">Bienvenido Administrador</div>
    <div class="cin-line"></div>
    <div class="cin-sub">Sistema de Información Geográfica Energético-Minero</div>
  </div>
</div>

<div class="cin-overlay" id="cin-logo">
    <div class="cin-box">
      <img src="assets/img/cvc-logo.png" alt="CVC Energía"/>
      <div class="cin-logo-fb" id="cvcFb" style="display:none">CVC ENERGÍA</div>
    <div class="cin-logo-sub">Soluciones Energéticas del Perú</div>
  </div>
</div>

<div id="cin-flash"></div>

<!-- ═══════════════════════════════════════
     SISTEMA PRINCIPAL (mapa)
═══════════════════════════════════════ -->
<div id="app" class="<?= $isAuth ? 'visible' : '' ?>">

  <!-- HEADER -->
  <header id="hdr">
    <div class="hdr-brand">
      <img class="cvc-logo hdr-logo" src="assets/img/cvc-logo.png" alt="CVC Energía"/>
      <div>
        <div class="hdr-name">GeoVisor Eléctrico</div>
        <div class="hdr-sub">CVC Energía · Perú</div>
      </div>
    </div>
    <div class="hdr-mid">
      <div class="srch-box">
        <span class="srch-ico">🔍</span>
        <input type="text" id="searchInput" placeholder="Buscar SET, línea, empresa o región… (Enter)"/>
        <div id="searchSuggest" class="search-suggest" role="listbox" aria-label="Resultados del buscador"></div>
      </div>
      <div class="hdr-sep"></div>
      <button class="tbtn" id="btnBase">🗺️ Calles (OSM)</button>
      <button class="tbtn" id="btnHome">📍 Perú</button>
      <button class="tbtn" id="btnFs">⛶</button>
    </div>
    <div class="hdr-right">
      <div class="usr-av"><?= strtoupper(substr($username,0,1)) ?></div>
      <div>
        <div class="usr-name"><?= htmlspecialchars($username) ?></div>
        <div class="usr-role"><?= $role === 'admin' ? 'Administrador' : 'Visualizador' ?></div>
      </div>
      <a href="<?= htmlspecialchars(csrfUrl('logout.php')) ?>" class="btn-out">Salir</a>
    </div>
    <button class="menu-btn" id="menuBtn">☰</button>
  </header>

  <!-- BODY -->
  <div id="body">

    <!-- SIDEBAR -->
    <aside id="sb">
      <div id="sb-dragbar">
        <span>Capas y análisis</span>
        <button type="button" id="sbClose" title="Ocultar panel">×</button>
      </div>
      <div id="sb-sc">

        <!-- KPIs -->
        <div class="sb-grp" style="padding-bottom:12px">
          <div class="sb-grp-t">Estado del sistema <span class="grp-cnt">Activo</span></div>
          <div class="sb-kpis">
            <div class="sb-kpi">
              <div class="sb-kpi-l">SET oficiales</div>
              <div class="sb-kpi-v" id="kpiSetCount">0</div>
              <div class="sb-kpi-u">en operación</div>
            </div>
            <div class="sb-kpi g">
              <div class="sb-kpi-l">Líneas transmisión</div>
              <div class="sb-kpi-v" id="kpiLtCount">0</div>
              <div class="sb-kpi-u">en operación</div>
            </div>
            <div class="sb-kpi a full">
              <div class="sb-kpi-l">Fuente geográfica activa</div>
              <div class="sb-kpi-v" id="kpiSource">OSINERGMIN</div>
              <div class="sb-kpi-u">MapServer / WMS electricidad</div>
            </div>
          </div>
        </div>

        <!-- Menús jerárquicos de capas -->
        <div class="sb-menu-tabs" role="tablist" aria-label="Menús de capas">
          <?php foreach ($sidebarTree as $menuName => $_groups): ?>
          <button type="button"
                  class="sb-menu-tab <?= $menuName === 'Electricidad' ? 'active' : '' ?>"
                  data-layer-menu="<?= htmlspecialchars(sidebarSlug($menuName)) ?>">
            <?= htmlspecialchars($menuName) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <div class="sb-menu-panels">
          <?php foreach ($sidebarTree as $menuName => $groups): ?>
          <section class="sb-menu-panel <?= $menuName === 'Electricidad' ? 'active' : '' ?>"
                   data-layer-menu-panel="<?= htmlspecialchars(sidebarSlug($menuName)) ?>">
            <div class="sb-menu-title"><?= htmlspecialchars($menuName) ?></div>

            <?php foreach ($groups as $groupName => $subgroups): ?>
            <details class="sb-grp" open>
              <summary class="sb-grp-t">
                <?= htmlspecialchars($groupName) ?>
                <span class="grp-cnt"><?= array_sum(array_map(fn($sg) => count($sg['local'] ?? []) + count($sg['api'] ?? []), $subgroups)) ?></span>
              </summary>
              <div class="sb-grp-body">
                <?php foreach ($subgroups as $subgroupName => $items): ?>
                <details class="sb-subgrp" open>
                  <summary><?= htmlspecialchars($subgroupName) ?> <span><?= count($items['local'] ?? []) + count($items['api'] ?? []) ?></span></summary>
                  <div class="sb-subgrp-body">
                    <?php foreach (($items['local'] ?? []) as $c): ?>
                    <div class="lyr <?= $c['activa'] ? 'on' : '' ?>"
                         id="lyr-<?= htmlspecialchars($c['id']) ?>"
                         data-id="<?= htmlspecialchars($c['id']) ?>"
                         data-tipo="<?= htmlspecialchars($c['tipo']) ?>"
                         data-color="<?= htmlspecialchars($c['color']) ?>">
                      <div class="lyr-chk"><?= $c['activa'] ? '✓' : '' ?></div>
                      <div class="lyr-dot" style="background:<?= htmlspecialchars($c['color']) ?>"></div>
                      <div>
                        <div class="lyr-name"><?= htmlspecialchars($c['nombre']) ?></div>
                        <div class="lyr-desc"><?= $c['tipo']==='point'?'Puntos':($c['tipo']==='line'?'Líneas':'Polígonos') ?></div>
                      </div>
                    </div>
                    <div class="lyr-op">
                      <input type="range" min="0" max="1" step=".05" value=".85" data-id="<?= htmlspecialchars($c['id']) ?>"/>
                      <span>85%</span>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach (($items['api'] ?? []) as $api): ?>
                    <div class="lyr"
                         id="lyr-<?= htmlspecialchars($api['id']) ?>"
                         data-id="<?= htmlspecialchars($api['id']) ?>"
                         data-provider="api"
                         data-tipo="<?= htmlspecialchars($api['tipo']) ?>"
                         data-color="<?= htmlspecialchars($api['color']) ?>">
                      <div class="lyr-chk"></div>
                      <div class="lyr-dot" style="background:<?= htmlspecialchars($api['color']) ?>"></div>
                      <div>
                        <div class="lyr-name"><?= htmlspecialchars($api['name']) ?></div>
                        <div class="lyr-desc"><?= $api['tipo']==='point'?'Puntos':($api['tipo']==='line'?'Líneas':'Polígonos') ?></div>
                      </div>
                    </div>
                    <div class="lyr-op">
                      <input type="range" min="0" max="1" step=".05" value=".85" data-id="<?= htmlspecialchars($api['id']) ?>"/>
                      <span>85%</span>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </details>
                <?php endforeach; ?>
              </div>
            </details>
            <?php endforeach; ?>

            <?php if ($menuName === 'Electricidad'): ?>
            <details class="sb-grp" open>
              <summary class="sb-grp-t">API OSINERGMIN <span class="grp-cnt">Oficial</span></summary>
              <div class="sb-grp-body">
                <details class="sb-subgrp" open>
                  <summary>Capas OSINERGMIN <span>9</span></summary>
                  <div class="sb-subgrp-body">
                    <div class="lyr" id="lyr-osinergmin-generacion" data-id="osinergmin-generacion" data-provider="osinergmin" data-os-layer="generacion" data-tipo="point" data-color="#ef4444">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#ef4444"></div>
                      <div><div class="lyr-name">Centrales de generacion</div><div class="lyr-desc">Centrales nacionales - potencia, fuente y estado</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-generacion"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-ceraunico" data-id="osinergmin-ceraunico" data-provider="osinergmin" data-os-layer="ceraunico" data-tipo="raster" data-color="#7c3aed">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#7c3aed"></div>
                      <div><div class="lyr-name">CERAUNICO descargas atmosfericas</div><div class="lyr-desc">Rayos totales por km2/año - 2025</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".90" data-id="osinergmin-ceraunico"/><span>90%</span></div>

                    <div class="lyr" id="lyr-osinergmin-set-op" data-id="osinergmin-set-op" data-provider="osinergmin" data-os-layer="18" data-tipo="point" data-color="#0ea5e9">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#0ea5e9"></div>
                      <div><div class="lyr-name">SET en operacion</div><div class="lyr-desc">Capa 18 - nombres y empresas</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-set-op"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-lt-op" data-id="osinergmin-lt-op" data-provider="osinergmin" data-os-layer="15" data-tipo="line" data-color="#2563eb">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#2563eb"></div>
                      <div><div class="lyr-name">LT en operacion</div><div class="lyr-desc">Capa 15 - tension nominal</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-lt-op"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-set-pr" data-id="osinergmin-set-pr" data-provider="osinergmin" data-os-layer="19" data-tipo="point" data-color="#f59e0b">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#f59e0b"></div>
                      <div><div class="lyr-name">SET proyectadas</div><div class="lyr-desc">Capa 19</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-set-pr"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-lt-pr" data-id="osinergmin-lt-pr" data-provider="osinergmin" data-os-layer="16" data-tipo="line" data-color="#ec4899">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#ec4899"></div>
                      <div><div class="lyr-name">LT proyectadas</div><div class="lyr-desc">Capa 16</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-lt-pr"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-mt-salida" data-id="osinergmin-mt-salida" data-provider="osinergmin" data-os-layer="21" data-tipo="point" data-color="#f97316">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#f97316"></div>
                      <div><div class="lyr-name">Salidas MT desde SET</div><div class="lyr-desc">Capa 21 - distribución</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-mt-salida"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-mt-tramo" data-id="osinergmin-mt-tramo" data-provider="osinergmin" data-os-layer="22" data-tipo="line" data-color="#fb923c">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#fb923c"></div>
                      <div><div class="lyr-name">Tramos media tension</div><div class="lyr-desc">Capa 22 - empresa y nivel de tension</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-mt-tramo"/><span>85%</span></div>

                    <div class="lyr" id="lyr-osinergmin-conc" data-id="osinergmin-conc" data-provider="osinergmin" data-os-layer="33" data-tipo="polygon" data-color="#22c55e">
                      <div class="lyr-chk"></div><div class="lyr-dot" style="background:#22c55e"></div>
                      <div><div class="lyr-name">Área de concesión</div><div class="lyr-desc">Capa 33</div></div>
                    </div>
                    <div class="lyr-op"><input type="range" min="0" max="1" step=".05" value=".85" data-id="osinergmin-conc"/><span>85%</span></div>
                  </div>
                </details>
              </div>
            </details>
            <?php endif; ?>

            <?php if ($menuName === 'Predios'): ?>
            <details class="sb-grp">
              <summary class="sb-grp-t">SUNARP BGR <span class="grp-cnt">visor</span></summary>
              <div class="sunarp-card">
                <strong>Visor grafico SUNARP</strong>
                <span>Referencia externa para partidas y predios. El propietario depende de la consulta oficial de SUNARP.</span>
                <a href="https://visor-bgr.sunarp.gob.pe/visor-bgr" target="_blank" rel="noopener">Abrir BGR</a>
                <a href="https://conoce-aqui.sunarp.gob.pe/conoce-aqui/inicio" target="_blank" rel="noopener">Consultar partida</a>
              </div>
            </details>
            <?php endif; ?>
          </section>
          <?php endforeach; ?>
        </div>

        <?php if (false): ?>
        <!-- Capas por grupo -->
        <?php foreach ($grupos as $grpName => $items): ?>
        <details class="sb-grp" open>
          <summary class="sb-grp-t">
            <?= htmlspecialchars($grpName) ?>
            <span class="grp-cnt"><?= count($items) ?></span>
          </summary>
          <div class="sb-grp-body">
          <?php foreach ($items as $c): ?>
          <div class="lyr <?= $c['activa'] ? 'on' : '' ?>"
               id="lyr-<?= $c['id'] ?>"
               data-id="<?= htmlspecialchars($c['id']) ?>"
               data-tipo="<?= htmlspecialchars($c['tipo']) ?>"
               data-color="<?= htmlspecialchars($c['color']) ?>">
            <div class="lyr-chk"><?= $c['activa'] ? '✓' : '' ?></div>
            <div class="lyr-dot" style="background:<?= htmlspecialchars($c['color']) ?>"></div>
            <div>
              <div class="lyr-name"><?= htmlspecialchars($c['nombre']) ?></div>
              <div class="lyr-desc"><?= $c['tipo']==='point'?'Puntos':($c['tipo']==='line'?'Líneas':'Polígonos') ?></div>
            </div>
          </div>
          <div class="lyr-op">
            <input type="range" min="0" max="1" step=".05" value=".85" data-id="<?= $c['id'] ?>"/>
            <span>85%</span>
          </div>
          <?php endforeach; ?>
          </div>
        </details>
        <?php endforeach; ?>

        <?php if (!empty($apis)): ?>
        <details class="sb-grp" open>
          <summary class="sb-grp-t">APIs externas <span class="grp-cnt"><?= count($apis) ?></span></summary>
          <div class="sb-grp-body">
          <?php foreach ($apis as $api): ?>
          <div class="lyr"
               id="lyr-<?= htmlspecialchars($api['id']) ?>"
               data-id="<?= htmlspecialchars($api['id']) ?>"
               data-provider="api"
               data-tipo="<?= htmlspecialchars($api['tipo']) ?>"
               data-color="<?= htmlspecialchars($api['color']) ?>">
            <div class="lyr-chk"></div>
            <div class="lyr-dot" style="background:<?= htmlspecialchars($api['color']) ?>"></div>
            <div>
              <div class="lyr-name"><?= htmlspecialchars($api['name']) ?></div>
              <div class="lyr-desc"><?= $api['tipo']==='point'?'Puntos':($api['tipo']==='line'?'Líneas':'Polígonos') ?> · <?= htmlspecialchars(($api['menu'] ?? 'APIs externas') . ' / ' . ($api['grupo'] ?? 'APIs externas')) ?></div>
            </div>
          </div>
          <div class="lyr-op">
            <input type="range" min="0" max="1" step=".05" value=".85" data-id="<?= htmlspecialchars($api['id']) ?>"/>
            <span>85%</span>
          </div>
          <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>

        <details class="sb-grp">
          <summary class="sb-grp-t">Predios / SUNARP BGR <span class="grp-cnt">visor</span></summary>
          <div class="sunarp-card">
            <strong>Visor grafico SUNARP</strong>
            <span>Referencia externa para partidas y predios. El propietario depende de la consulta oficial de SUNARP.</span>
            <a href="https://visor-bgr.sunarp.gob.pe/visor-bgr" target="_blank" rel="noopener">Abrir BGR</a>
            <a href="https://conoce-aqui.sunarp.gob.pe/conoce-aqui/inicio" target="_blank" rel="noopener">Consultar partida</a>
          </div>
        </details>

        <!-- OSINERGMIN Electricidad -->
        <details class="sb-grp" open>
          <summary class="sb-grp-t">OSINERGMIN Electricidad <span class="grp-cnt">Oficial</span></summary>
          <div class="sb-grp-body">
          <div class="lyr" id="lyr-osinergmin-set-op" data-id="osinergmin-set-op"
               data-provider="osinergmin" data-os-layer="18" data-tipo="point" data-color="#0ea5e9">
            <div class="lyr-chk">✓</div>
            <div class="lyr-dot" style="background:#0ea5e9"></div>
            <div><div class="lyr-name">SET en operación</div><div class="lyr-desc">Capa 18 · nombres y empresas</div></div>
          </div>
          <div class="lyr" id="lyr-osinergmin-lt-op" data-id="osinergmin-lt-op"
               data-provider="osinergmin" data-os-layer="15" data-tipo="line" data-color="#2563eb">
            <div class="lyr-chk">✓</div>
            <div class="lyr-dot" style="background:#2563eb"></div>
            <div><div class="lyr-name">LT en operación</div><div class="lyr-desc">Capa 15 · tensión nominal</div></div>
          </div>
          <div class="lyr" id="lyr-osinergmin-set-pr" data-id="osinergmin-set-pr"
               data-provider="osinergmin" data-os-layer="19" data-tipo="point" data-color="#f59e0b">
            <div class="lyr-chk"></div>
            <div class="lyr-dot" style="background:#f59e0b"></div>
            <div><div class="lyr-name">SET proyectadas</div><div class="lyr-desc">Capa 19</div></div>
          </div>
          <div class="lyr" id="lyr-osinergmin-lt-pr" data-id="osinergmin-lt-pr"
               data-provider="osinergmin" data-os-layer="16" data-tipo="line" data-color="#ec4899">
            <div class="lyr-chk"></div>
            <div class="lyr-dot" style="background:#ec4899"></div>
            <div><div class="lyr-name">LT proyectadas</div><div class="lyr-desc">Capa 16</div></div>
          </div>
          <div class="lyr" id="lyr-osinergmin-mt-salida" data-id="osinergmin-mt-salida"
               data-provider="osinergmin" data-os-layer="21" data-tipo="point" data-color="#f97316">
            <div class="lyr-chk"></div>
            <div class="lyr-dot" style="background:#f97316"></div>
            <div><div class="lyr-name">Salidas MT desde SET</div><div class="lyr-desc">Capa 21 · distribución</div></div>
          </div>
          <div class="lyr" id="lyr-osinergmin-mt-tramo" data-id="osinergmin-mt-tramo"
               data-provider="osinergmin" data-os-layer="22" data-tipo="line" data-color="#fb923c">
            <div class="lyr-chk"></div>
            <div class="lyr-dot" style="background:#fb923c"></div>
            <div><div class="lyr-name">Tramos media tensión</div><div class="lyr-desc">Capa 22 · empresa y nivel de tensión</div></div>
          </div>
          <div class="lyr" id="lyr-osinergmin-conc" data-id="osinergmin-conc"
               data-provider="osinergmin" data-os-layer="33" data-tipo="polygon" data-color="#22c55e">
            <div class="lyr-chk"></div>
            <div class="lyr-dot" style="background:#22c55e"></div>
            <div><div class="lyr-name">Área de concesión</div><div class="lyr-desc">Capa 33</div></div>
          </div>
          </div>
        </details>

        <?php endif; ?>

        <!-- Admin -->
        <div class="sb-grp admin-only" style="padding-bottom:16px">
          <div class="sb-grp-t">Administración</div>
          <a href="upload.php" class="btn-admin">⚙️ &nbsp;Gestionar datos y capas</a>
        </div>

      </div><!-- /sb-sc -->
    </aside>

    <!-- MAPA -->
    <div id="map-wrap">
      <div id="map"></div>
      <div id="layer-dock" aria-label="Herramientas del visor">
        <button type="button" id="dockLayers" title="Capas">▣</button>
        <button type="button" id="dockLegend" title="Leyenda">◫</button>
        <button type="button" id="dockDraw" title="Dibujar zona">&#9998;</button>
        <button type="button" id="dockClearZone" title="Limpiar zona">×</button>
        <button type="button" id="dockZones" title="Zonas sugeridas">◎</button>
        <button type="button" id="dockTempGeo" title="Archivo geografico temporal">GEO</button>
        <button type="button" id="dockAiChat" title="Chat OpenAI">AI</button>
        <a href="upload.php" title="Administración" class="admin-only">⚙</a>
      </div>

      <section id="aiChatPanel" class="ai-chat-panel" aria-label="Chat OpenAI">
        <div class="ai-chat-head">
          <div>
            <strong>Asistente IA territorial</strong>
            <span>Consulta mejores zonas con la data cargada</span>
          </div>
          <div class="ai-chat-actions">
            <button type="button" id="aiChatNew" aria-label="Nueva conversacion">↻</button>
            <button type="button" id="aiChatMin" aria-label="Minimizar chat">−</button>
            <button type="button" id="aiChatClose" aria-label="Cerrar chat">×</button>
          </div>
        </div>
        <div id="aiChatMessages" class="ai-chat-messages">
          <div class="ai-msg ai-msg-bot">Pregúntame por zonas viables para concesión o convenio. Puedo contrastar SET, concesiones, cultivos, acuicultura y media tensión.</div>
        </div>
        <form id="aiChatForm" class="ai-chat-form">
          <textarea id="aiChatInput" rows="2" placeholder="Ej.: ¿Dónde conviene evaluar una concesión agrícola fuera de concesiones?"></textarea>
          <button type="submit">Enviar</button>
        </form>
      </section>

      <section id="analysisPanel" class="analysis-panel" aria-label="Análisis técnico">
        <div class="analysis-panel-head">
          <div>
            <strong id="analysisPanelTitle">Análisis técnico</strong>
            <span id="analysisPanelSub">Disponibilidad, carga y PIT</span>
          </div>
          <button type="button" id="analysisPanelClose" aria-label="Cerrar análisis">×</button>
        </div>
        <div id="analysisPanelBody" class="analysis-panel-body"></div>
      </section>

      <section id="tempGeoPanel" class="temp-geo-panel" aria-label="Archivo geografico temporal">
        <div class="temp-geo-head">
          <div>
            <strong>Archivo geografico temporal</strong>
            <span>KML, KMZ, GeoJSON o JSON; se elimina al limpiar o recargar</span>
          </div>
          <button type="button" id="tempGeoClose" aria-label="Cerrar panel temporal">×</button>
        </div>
        <form id="tempGeoForm" class="temp-geo-form">
          <label class="temp-field">
            <span>Archivo</span>
            <input type="file" id="tempGeoFile" accept=".kml,.kmz,.geojson,.json,application/vnd.google-earth.kml+xml,application/vnd.google-earth.kmz,application/geo+json,application/json" required>
          </label>
          <div class="temp-grid">
            <label class="temp-field">
              <span>Evaluación</span>
              <select id="tempGeoMode">
                <option value="multiple">Múltiple</option>
                <option value="transmision">Línea de transmisión</option>
                <option value="distribucion">Línea de distribución</option>
                <option value="poligono">Polígono</option>
              </select>
            </label>
            <div id="tempLineDefaults" class="temp-line-defaults">
              <label class="temp-field">
                <span>Tensión kV</span>
                <input type="number" id="tempVoltageKv" min="1" step="0.1" value="22.9">
              </label>
              <label class="temp-field">
                <span>Terna</span>
                <select id="tempCircuitType">
                  <option value="simple">Simple</option>
                  <option value="doble">Doble</option>
                </select>
              </label>
            </div>
          </div>
          <div class="temp-actions">
            <button type="submit">Cargar</button>
            <button type="button" id="tempGeoClear">Limpiar</button>
          </div>
        </form>
        <div id="tempGeoSummary" class="temp-geo-summary"></div>
      </section>

      <section id="geoWorkspace" class="geo-workspace" aria-label="Taller GEO y KML" aria-hidden="true">
        <header class="geo-workspace-topbar">
          <div class="geo-brand">
            <button type="button" id="geoWorkspaceClose" class="geo-icon-btn" title="Volver al geovisor" aria-label="Volver al geovisor">&#8592;</button>
            <div>
              <strong>Taller GEO / KML</strong>
              <span id="geoWorkspaceStatus">Proyecto temporal · los cambios no se guardan automáticamente</span>
            </div>
          </div>
          <div class="geo-top-actions">
            <label class="geo-file-btn" for="geoWorkspaceFile">Abrir KML, KMZ o GeoJSON</label>
            <input type="file" id="geoWorkspaceFile" accept=".kml,.kmz,.geojson,.json,application/vnd.google-earth.kml+xml,application/vnd.google-earth.kmz,application/geo+json,application/json" hidden>
            <input type="text" id="geoProjectName" class="geo-project-name" maxlength="90" placeholder="Nombre del proyecto">
            <button type="button" id="geoProjectSave">Guardar proyecto</button>
            <select id="geoProjectSelect" aria-label="Proyectos guardados">
              <option value="">Proyectos guardados</option>
            </select>
            <button type="button" id="geoProjectLoad">Abrir</button>
            <button type="button" id="geoPresentStart" class="geo-present-btn">Presentar</button>
          </div>
        </header>

        <aside class="geo-workspace-left">
          <div class="geo-panel-title">
            <div><strong>Contenido del mapa</strong><span>Capas y carpetas del archivo</span></div>
            <button type="button" id="geoTreeRefresh" class="geo-icon-btn" title="Actualizar capas">&#8635;</button>
          </div>
          <div class="geo-tree-tools">
            <button type="button" id="geoAllOn">Mostrar todo</button>
            <button type="button" id="geoAllOff">Ocultar GEO</button>
          </div>
          <div id="geoWorkspaceTree" class="geo-workspace-tree"></div>
        </aside>

        <aside class="geo-workspace-right">
          <div class="geo-panel-title">
            <div><strong id="geoInspectorTitle">Medición y análisis</strong><span id="geoInspectorSubtitle">Seleccione o dibuje un elemento</span></div>
            <button type="button" id="geoInspectorClose" class="geo-icon-btn" title="Ocultar panel">&#215;</button>
          </div>
          <div id="geoInspectorBody" class="geo-inspector-body">
            <div class="geo-empty-state">Seleccione una ruta, polígono o punto para ver longitud, perímetro, área, perfil de elevación y opciones de análisis con IA.</div>
          </div>
        </aside>

        <div class="geo-draw-toolbar" role="toolbar" aria-label="Herramientas de dibujo">
          <button type="button" data-geo-draw="point" title="Añadir punto">Punto</button>
          <button type="button" data-geo-draw="line" title="Dibujar ruta">Ruta</button>
          <button type="button" data-geo-draw="polygon" title="Dibujar polígono">Polígono</button>
          <button type="button" id="geoDrawUndo" title="Deshacer último vértice">Deshacer</button>
          <button type="button" id="geoDrawFinish" title="Finalizar dibujo">Finalizar</button>
          <button type="button" id="geoAddScene" title="Guardar vista para presentación">+ Vista</button>
        </div>

        <div id="geoDrawHint" class="geo-draw-hint" hidden></div>
        <div id="geoPresentationBar" class="geo-presentation-bar" hidden>
          <button type="button" id="geoPresentPrev" aria-label="Vista anterior">&#8249;</button>
          <span id="geoPresentCounter">1 / 1</span>
          <input type="text" id="geoSceneTitle" maxlength="80" placeholder="Título de la vista">
          <button type="button" id="geoPresentNext" aria-label="Vista siguiente">&#8250;</button>
          <button type="button" id="geoPresentStop">Salir de presentación</button>
        </div>
      </section>

      <!-- Leyenda -->
      <div id="legend">
        <div class="leg-t">Leyenda activa</div>
        <div id="legendBody" class="leg-body">
          <div class="leg-empty">Activa una capa para ver su simbologia.</div>
        </div>
      </div>

      <div id="legendLegacy" hidden>
        <div class="leg-t">Leyenda de capas</div>
        <div class="leg-it">
          <div style="width:11px;height:11px;border-radius:50%;background:#0ea5e9;border:2px solid #fff;flex-shrink:0"></div>
          <span>SET en operación</span>
        </div>
        <div class="leg-it">
          <div style="width:11px;height:11px;border-radius:50%;background:#f59e0b;border:2px solid #fff;flex-shrink:0"></div>
          <span>SET proyectada</span>
        </div>
        <div class="leg-it">
          <div style="width:30px;height:3px;border-radius:2px;background:#2563eb;flex-shrink:0"></div>
          <span>LT 220 kV</span>
        </div>
        <div class="leg-it">
          <div style="width:30px;height:3px;border-radius:2px;background:#16a34a;flex-shrink:0"></div>
          <span>LT 138 kV</span>
        </div>
        <div class="leg-it">
          <div style="width:30px;height:3px;border-radius:2px;background:#dc2626;flex-shrink:0"></div>
          <span>LT 60 kV / menor</span>
        </div>
        <div class="leg-it">
          <div style="width:28px;height:10px;border-radius:3px;background:#16a34a;opacity:.42;flex-shrink:0"></div>
          <span>Concesión vigente</span>
        </div>
        <div class="leg-it">
          <div style="width:28px;height:10px;border-radius:3px;background:#f59e0b;opacity:.42;flex-shrink:0"></div>
          <span>Concesión en trámite</span>
        </div>
        <div class="leg-src">Fuente: OSINERGMIN Electricidad + Shapefile.zip</div>
      </div>

      <!-- KPI global -->
      <div id="kpi-g">
        <div class="gk"><div class="gk-v" id="gSet">0</div><div class="gk-l">SET</div></div>
        <div class="gk"><div class="gk-v" id="gLt" style="color:var(--green)">0</div><div class="gk-l">LT</div></div>
        <div class="gk"><div class="gk-v" id="gCapas">0</div><div class="gk-l">Capas</div></div>
      </div>
    </div><!-- /map-wrap -->

  </div><!-- /body -->
</div><!-- /app -->

<!-- TOASTS -->
<div id="toasts"></div>

<!-- DATOS PHP → JS -->
<script>
const _AUTH    = <?= $isAuth ? 'true' : 'false' ?>;
const _ROLE    = '<?= addslashes($role) ?>';
const _SUBS    = <?= SUBS ?>;
const CSRF_TOKEN = <?= json_encode(csrfToken(), JSON_UNESCAPED_SLASHES) ?>;
const _CAPAS   = <?= json_encode($capas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const _APIS    = <?= json_encode($apis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const _URL_ELEC   = '<?= URL_ELEC ?>';
const _URL_ELEC_REST = '<?= URL_ELEC_REST ?>';
const _URL_ELEC_WMS  = '<?= URL_ELEC_WMS ?>';
const _URL_CERAUNICO_REST = '<?= URL_CERAUNICO_REST ?>';
</script>

<script src="assets/vendor/three.min.js"></script>
<script src="assets/vendor/leaflet.min.js"></script>
<script src="assets/js/app.js?v=<?= filemtime(ROOT . '/assets/js/app.js') ?>"></script>
<script src="assets/js/geo-workspace.js?v=<?= is_file(ROOT . '/assets/js/geo-workspace.js') ? filemtime(ROOT . '/assets/js/geo-workspace.js') : time() ?>"></script>
</body>
</html>
