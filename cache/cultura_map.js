let Mapa = {
    Maestros: null,
    Data: [],
    MapDiv: document.getElementById("map"),
    //Centroide de inicio
    Center: [-9.7213829, -73.5501206],

    //Path Base 
    BasePath: null,

    //Identidad
    Info: "mapa/verInformacionPoligono",
    Contacto: "mapa/enviarContacto",
    Download: "mapa/descargar",
    MaxZoomPoint: 17,
    MaxZoomPoligon: 15,
    AreaDescargar: 50000000000,
    MaxZoomImpresion : 8,

    Bounds: null,
    BSOffcanvas: null,
    MarkerSelected: null,
    IconFeature: null,

    // //Servidor de impresion
    // ServerPrint: "http://localhost:8080/geoserver/pdf/create.json",        
    // //Servidor de capas
    // ServerMap: "http://localhost:8080/geoserver/",


    // //Servidor de impresion
    ServerPrint: "https://geoserverdesa.cultura.gob.pe:8443/geoserver/pdf/create.json",
    // //Servidor de capas
 //ServerMap: "https://geoserverdesa.cultura.gob.pe:8443/geoserver/",
    ServerMap: "https://geoservicios.cultura.gob.pe/geoserver/",

    //Mapas base
    MapBase: {
        "Satélite": L.tileLayer('https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '',
            maxNativeZoom: 19,
            maxZoom: 23
        }),

        "Callejero": L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '',
            maxNativeZoom: 19,
            maxZoom: 23
        }),

        "Topográfico": L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}', {
            attribution: '',
            maxNativeZoom: 19,
            maxZoom: 23
        })

    },
    //Niveles de zoom para impresion
    Level: [{
            level: 0,
            resolution: 156543.033928,
            scale: 591657527.591555,
            label: 60000000,
        },
        {
            level: 1,
            resolution: 78271.5169639999,
            scale: 295828763.795777,
            label: 30000000,
        },
        {
            level: 2,
            resolution: 39135.7584820001,
            scale: 147914381.897889,
            label: 15000000,
        },
        {
            level: 3,
            resolution: 19567.8792409999,
            scale: 73957190.948944,
            label: 7400000,
        },
        {
            level: 4,
            resolution: 9783.93962049996,
            scale: 36978595.474472,
            label: 3700000,
        },
        {
            level: 5,
            resolution: 4891.96981024998,
            scale: 18489297.73724,
            label: 1900000,
        },
        {
            level: 6,
            resolution: 2445.98490512499,
            scale: 9244648.86862,
            label: 1000000,
        },
        {
            level: 7,
            resolution: 1222.99245256249,
            scale: 4622324.43431,
            label: 500000,
        },
        {
            level: 8,
            resolution: 611.49622628125,
            scale: 2311162.21715,
            label: 240000,
        },
        {
            level: 9,
            resolution: 305.74811314062,
            scale: 1155581.10858,
            label: 120000,
        },
        {
            level: 10,
            resolution: 152.87405657031,
            scale: 577790.55429,
            label: 600000,
        },
        {
            level: 11,
            resolution: 76.43702828516,
            scale: 288895.27714,
            label: 300000,
        },
        {
            level: 12,
            resolution: 38.21851414258,
            scale: 144447.63857,
            label: 145000,
        },
        {
            level: 13,
            resolution: 19.10925707129,
            scale: 72223.81929,
            label: 75000,
        },
        {
            level: 14,
            resolution: 9.55462853564,
            scale: 36111.90964,
            label: 36000,
        },
        {
            level: 15,
            resolution: 4.77731426782,
            scale: 18055.95482,
            label: 18000,
        },
        {
            level: 16,
            resolution: 2.38865713391,
            scale: 9027.97741,
            label: 10000,
        },
        {
            level: 17,
            resolution: 1.19432856696,
            scale: 4513.98871,
            label: 5000,
        },
        {
            level: 18,
            resolution: 0.59716428348,
            scale: 2256.99435,
            label: 2500,
        },
        {
            level: 19,
            resolution: 0.29858214174,
            scale: 1128.49718,
            label: 1200,
        },
        {
            level: 20,
            resolution: 0.14929107087,
            scale: 564.24859,
            label: 600,
        },
        {
            level: 21,
            resolution: 0.07464553543,
            scale: 282.12429,
            label: 300,
        },
        {
            level: 22,
            resolution: 0.03732276772,
            scale: 141.06215,
            label: 150,
        },
        {
            level: 23,
            resolution: 0.01866138386,
            scale: 70,
            label: 75,
        }

    ],
    Zoom: null,
    ZoomInt: 6,
    Map: null,
    Medir: null,
    Layers: null,
    InfoLayers: [],
    Mensaje: null,
    Limites: [],
    Bounds : [
                [0.45952025020276854, -84.47512115624997],
                [-18.377182780444958, -65.86428131249997],
            ],
    idDepartamento: "layer_0_0_0",
    idProvincia: "layer_0_0_1",
    idDistrito: "layer_0_0_2",
    TypeDrawing: null,

        Init: () => {
          
            Mapa.Map = L.map('map', {
                editable: true,
                center: Mapa.Center,
                zoom: Mapa.ZoomInt,
                //scrollWheelZoom: true,
                minZoom: 6,
                maxBounds: Mapa.Bounds,
                maxZoom: 23,
                layers: [Mapa.MapBase["Satélite"]],
                crs: L.CRS.EPSG3857
            });

            Mapa.Zoom = Mapa.Map._zoom;
            const resizeObserver = new ResizeObserver(() => {
                Mapa.Map.invalidateSize();
            });

            document.querySelector("#Mapa-Imprimir-Procesar").disabled = ((Mapa.Map._zoom > Mapa.MaxZoomImpresion) ? false : true );
            Mapa.Map.on('zoomend', function() {
                document.querySelector("#Mapa-Imprimir-Procesar").disabled = ((Mapa.Map._zoom > Mapa.MaxZoomImpresion) ? false : true );

            });

            resizeObserver.observe(Mapa.MapDiv);
            Mapa.Medir = L.control.measure();
            Mapa.Medir.addTo(Mapa.Map);

            //Auxiliares
            Mapa.Bounds = Mapa.Map.getBounds();
            Mapa.BSOffcanvas = new bootstrap.Offcanvas(document.querySelector("#Mapa-Contenido"));

            Mapa.IconFeature = L.icon({
                iconUrl: Mapa.BasePath + "resources/img/maker/15.png",
                iconSize: [48, 48],
                shadowSize: [60, 60],
                iconAnchor: [40, 40],
                shadowAnchor: [4, 4],
                popupAnchor: [-25, -40]
            });

            document.querySelector(".leaflet-control-zoom").remove();
            document.querySelector(".leaflet-control-measure").remove();
            Mapa.Mensaje = new bootstrap.Toast(document.querySelector('#Mapa-Mensaje'));

            document.querySelector("#Mapa-Download-Ambito-Label").innerHTML = "(*) La carga de ámbito shapefile es en formato .zip<br>El archivo solo debe contener 1 polígono<br>El ámbito de dibujo no debe ser mas de " + new Intl.NumberFormat('es-PE').format(parseFloat( Mapa.AreaDescargar)) + " m2";
 
            Mapa.Maestros();
            Mapa.Events();
            Mapa.Load();

            base_url = Mapa.BasePath;
        },

        TOC: (response) => {
            let toc = document.querySelector("#Mapa-Layers");
            let html = '';
            //Creamos el TOC
            response.Temas.forEach((itemTema, i) => {
                html = '<div class="accordion-item">';

                html += '<h2 class="accordion-header" id="tema_' + itemTema.ide + '">';
                html += '<button class="accordion-button ' + (itemTema.isOpen ? "  " : " collapsed ") + '" type="button" data-bs-toggle="collapse" data-bs-target="#layer_collapse_' + itemTema.ide + '" aria-expanded="true" aria-controls="layer_collapse_' + itemTema.ide + '">';
                html += itemTema.nombre;
                html += '</button>';
                html += '</h2>';

                html += '<div id="layer_collapse_' + itemTema.ide + '" class="accordion-collapse collapse ' + (itemTema.isOpen ? " show  " : " ") + '" aria-labelledby="tema_' + itemTema.ide + '" data-bs-parent="#Mapa-Layers">';
                html += '<div class="accordion-body p-1">';
                itemTema.Grupos.forEach((itemGrupo, i) => {
                    html += '<a class="link-layer w-100 d-flex px-2 py-2 bg-light  wow animated  fadeIn  ' + (itemGrupo.isOpen ? " " : " collapsed ") + '" data-wow-delay="0.5s" data-bs-toggle="collapse" data-bs-target="#layer_collapse_' + itemTema.ide + '_' + itemGrupo.ide + '" aria-expanded="false" aria-controls="layer_collapse_' + itemTema.ide + '">';
                    html += '<small class="w-100 text-dark"> ' + itemGrupo.nombre + '</small>';
                    html += '</a>';

                    html += '<div class="collapse collapse-horizontal p-2 wow animated  fadeIn ' + (itemGrupo.isOpen ? " collapse show " : " ") + '" data-wow-delay="0.5s" id="layer_collapse_' + itemTema.ide + '_' + itemGrupo.ide + '">';

                    itemGrupo.Layers.forEach((itemLayer, i) => {

                        html += '<div class="d-flex">';
                        html += '<div class="w-100">';
                        html += '<div class="form-check form-switch w-100">';
                        html += '<input class="form-check-input clsLayers" type="checkbox" id="layer_' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" ' + (itemLayer.activa ? " checked " : " ") + ' data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" data-type="' + itemLayer.tipo + '" data-unico="' + (itemGrupo.isUnico ? 1 : 0) + '" data-download="' + (itemLayer.isDownload ? 1 : 0) + '" data-idendownload="' + itemLayer.idenDownload + '"  >';
                        //html += '<label class="form-check-label w-100" for="layer_' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '">' + (itemLayer.isDownload ? '<i class="fas fa-download" style="color: green;" title="Descargable" alt="Descargable"></i> ' : ' ') + itemLayer.nombre + '</label>';
                        html += '<label class="form-check-label w-100" for="layer_' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '">'  + itemLayer.nombre + '</label>';
                        html += '</div>';
                        html += '</div>';

                        if (itemLayer.isExtend) {
                            html += '<div class="flex-shrink-1 ">';
                            html += '<a  data-bs-toggle="collapse" href="#popover-extend-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" id="popover-extend-icon-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" role="button" aria-expanded="false" aria-controls="#popover-extend-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" >  <i class="fas fa-gear"></i></a>';
                            html += '</div>';


                            let extend = '';
                            extend += '<div class="position-absolute  alert fade collapse  modal-popover z-index-front clsFilter" id="popover-extend-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" aria-hidden="true" aria-labelledby="popover-extend-Label-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" tabindex="-1"  data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" >';
                            extend += '<div class="modal-dialog draggable modal-sm ">';
                            extend += '<div class="modal-content">';
                            extend += '<div class="modal-header rounded-0">';
                            extend += '<h5 class="modal-title" id="popover-extend-Label-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '"> ' + itemLayer.nombre + '</h5>';
                            extend += '<button type="button" class="btn-modal-close" data-bs-toggle="collapse" data-bs-target="#popover-extend-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" aria-expanded="true" aria-controls="popover-content-1"><i class="fas fa-times"></i></button>';
                            extend += '</div>';
                            extend += '<div class="modal-body no-drag  p-2">';

                            //Opacidad
                            if (itemLayer.Extend.isOpacidad)
                                extend += '<input type="range" min="1" max="100" value="' + itemLayer.opacidad + '" class="form-range form-control-sm clsOpacidad" id="range-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-original-title="Opacidad" data-ide="' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '"></input>';

                            if (itemLayer.Extend.Filtros) {
                                itemLayer.Extend.Filtros.forEach((itemFiltros, iFiltros) => {
                                    extend += '<small for="LayerFiltro-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '_' + iFiltros + '" >' + itemFiltros.nombre + '</small>';

                                    if (itemFiltros.type == "select2"){
                                        extend += '<br><select  id="LayerFiltro-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '_' + iFiltros + '" class="clsLayerFiltro clsSelect2" data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" data-filtro="' + iFiltros + '" data-prefijo="' + itemFiltros.prefijo + '" data-split="' + itemFiltros.isSplit + '" data-fixed="' + (itemFiltros.isFixed ? 1 : 0) + '" style="width: 100%; font-size: 12px !important;">';
                                        if (itemFiltros.valores) {
                                            itemFiltros.valores.forEach((itemValores, iValores) => {
                                                extend += '<option value="' + itemValores.value + '" ' + (itemFiltros.default == itemValores.value ? " selected " : " ") + '>Select' + itemValores.text + '</option>';
                                            })
                                        }
                                        extend += '</select>';
                                    }else{
                                        extend += '<select  id="LayerFiltro-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '_' + iFiltros + '" class="form-select form-control-sm clsLayerFiltro" data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" data-filtro="' + iFiltros + '" data-prefijo="' + itemFiltros.prefijo + '" data-split="' + itemFiltros.isSplit + '" data-fixed="' + (itemFiltros.isFixed ? 1 : 0) + '" >';
                                        if (itemFiltros.valores) {
                                            itemFiltros.valores.forEach((itemValores, iValores) => {
                                                extend += '<option value="' + itemValores.value + '" ' + (itemFiltros.default == itemValores.value ? " selected " : " ") + '>' + itemValores.text + '</option>';
                                            })
                                        }
                                        extend += '</select>';
                                    }
                                   
                                });
                            }


                            if (itemLayer.Extend.Butones) {
                                itemLayer.Extend.Butones.forEach((itemButon, iButon) => {
                                    extend += '<div class="w-100 pt-2">';    
                                    if (itemButon.tipo == "estadistico") {
                                        extend += '<a id="LayerBoton-' + itemTema.ide + '_' + itemGrupo.ide + '_' + itemLayer.ide + '_' + iButon + '"  data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" data-boton="' + iButon + '" class="btn btn-sm btn-primary d-block hover-animation clsBotones" data-bs-toggle="offcanvas" href="#Mapa-Contenido" role="button" aria-controls="Mapa-Contenido"> ' + itemButon.titulo + '</a>';
                                    }
                                    extend += '</div>';
                                });
                            }

                            if (itemLayer.Extend.Links) {                                                                    
                                itemLayer.Extend.Links.forEach((itemLinks, iLinks) => {
                                    extend += '<div class="w-100 pt-2">';
                                    extend += '<button type="button" class="btn btn-sm btn-primary  hover-animation w-100 clsLinks" data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" data-link="' + iLinks + '">' + itemLinks.titulo + '</button>';
                                    extend += '</div>';    
                                    extend += '<div class="clsLinksContenedor" ' + (itemLinks.isOpen ? " " : " style = ' display: none !important; ' " )  + ' data-tema="' + itemTema.ide + '" data-grupo="' + itemGrupo.ide + '" data-layer="' + itemLayer.ide + '" data-link="' + iLinks + '">';
                                    extend += '<div class="list-group list-aplication" >';                                        
                                    itemLinks.items.forEach((itemLink, iLink) => {                                            
                                        extend += '<div class="d-flex w-100">';
                                        extend += '<a class="list-group-item fancybox" data-type="swf" title="' + itemLink.label + '" href="' + itemLink.url + '" ><i class="fas fa-video-camera mr-1 text-light"></i>' + itemLink.label + '</a>';
                                        extend += '</div>';                                            
                                    });    
                                    extend += '</div>';
                                    extend += '</div>';                                
                                });
                            }

                            extend += '</div>';
                            extend += '</div>';
                            extend += '</div>';
                            extend += '</div>';
                            $('body').append($(extend));
                        }

                        html += '</div>';
                    })
                    html += '</div>';

                });
                html += '</div>';
                html += '</div>';
                html += '</div>';
                toc.innerHTML += html;

                $(".modal-popover").mousedown(function () {
                    $(".draggable").draggableTouch();
                });

                $(".no-drag").mousedown(function() {
                    $(".draggable").draggableTouch("disable");
                });

                  
                $(".clsLinksContenedor").mCustomScrollbar({
                    setHeight: 600,
                    theme: "minimal-dark"
                });


            });

            


            let clsSelect2 = document.querySelectorAll(".clsSelect2");
            if (clsSelect2) {
                clsSelect2.forEach((item, i) => {
                    $('#LayerFiltro-' + item.dataset.tema + '_' + item.dataset.grupo + '_' + item.dataset.layer + '_' + item.dataset.filtro).select2({
                        dropdownParent: $('#popover-extend-' + item.dataset.tema + '_' + item.dataset.grupo + '_' + item.dataset.layer )
                    }).on("select2:select", function (e) { 
                        document.querySelector('#LayerFiltro-' + item.dataset.tema + '_' + item.dataset.grupo + '_' + item.dataset.layer + '_' + item.dataset.filtro).dispatchEvent(new Event("change"));
                    });
                });
            }

            let clsLinks = document.querySelectorAll(".clsLinks");
            if (clsLinks) {
                clsLinks.forEach((item, i) => {
                    item.addEventListener("click", (e) => {
                        let tema = e.target.dataset.tema;
                        let grupo = e.target.dataset.grupo;
                        let idLayer = e.target.dataset.layer;
                        let link = e.target.dataset.link;
                        let div = document.querySelector(".clsLinksContenedor[data-tema='" + tema + "'][data-grupo='" + grupo + "'][data-layer='" + idLayer + "'][data-link='" + link + "']");
                        if (div){
                            if (div.style.display == "none")
                                div.style.display = "block";
                            else
                                div.style.display = "none";
                        }                                            
                    });
                });
            }


            //Agregamos capas
            response.Temas.forEach((itemTema, i) => {
                itemTema.Grupos.forEach((itemGrupo, i) => {
                    itemGrupo.Layers.forEach((itemLayer, i) => {
                        if (itemLayer.activa) {
                            Mapa.addLayer(itemTema.ide, itemGrupo.ide, itemLayer);
                        }
                    })
                });

            });

            //Agregamos eventos a capas
            let clsLayer = document.querySelectorAll(".clsLayers");
            if (clsLayer) {
                clsLayer.forEach((item, i) => {

                    item.addEventListener("click", (e) => {
                        let tema = e.target.dataset.tema;
                        let grupo = e.target.dataset.grupo;
                        let idLayer = e.target.dataset.layer;
                        let checked = e.target.checked;
                        let layer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == idLayer);
                        let Grupo = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo);

                        if (Grupo.isUnico) {
                            Grupo.Layers.filter(resp => resp.ide != idLayer).forEach((item, i) => {
                                document.querySelector("#layer_" + tema + "_" + grupo + "_" + item.ide).checked = false;
                                Mapa.getLayer("layer_" + tema + "_" + grupo + "_" + item.ide).forEach((itemLayer, i) => {
                                    Mapa.Map.removeLayer(itemLayer);
                                    if (document.querySelector("#btn-tabLeyenda-" + tema + "_" + grupo + "_" + item.ide)) {
                                        document.querySelector("#btn-tabLeyenda-" + tema + "_" + grupo + "_" + item.ide).parentNode.remove();
                                        document.querySelector("#tabLeyenda-" + tema + "_" + grupo + "_" + item.ide).remove();
                                    }
                                    if (document.querySelector("#popover-extend-" + tema + "_" + grupo + "_" + item.ide)) {
                                        $("#popover-extend-" + tema + "_" + grupo + "_" + item.ide).collapse('hide');
                                    }
                                });
                            });
                        }

                        let ide_layer = "layer_" + tema + "_" + grupo + "_" + idLayer;
                        let ide_leyenda = "btn-tabLeyenda-" + tema + "_" + grupo + "_" + idLayer;
                        let ide_leyendaContenido = "tabLeyenda-" + tema + "_" + grupo + "_" + idLayer;

                        let existeLayer = Mapa.getLayer(ide_layer);
                        if (checked) {
                            if (existeLayer.length == 0) {
                                if (layer.isExtend) {
                                    if (layer.Extend) {
                                        if (layer.Extend.Filtros == 0) {
                                            Mapa.addLayer(tema, grupo, layer);
                                        } else {
                                            document.querySelector("#Mapa-Filter-Distrito").dispatchEvent(new Event("change"));
                                        }
                                    } else {
                                        Mapa.addLayer(tema, grupo, layer);
                                    }

                                    if (document.querySelector("#popover-extend-" + tema + "_" + grupo + "_" + idLayer))
                                        document.querySelector("#popover-extend-" + tema + "_" + grupo + "_" + idLayer).classList.add("show");

                                } else {
                                    Mapa.addLayer(tema, grupo, layer);
                                }
                            }
                        } else {
                            if (existeLayer) {
                                if (document.querySelector("#popover-extend-" + tema + "_" + grupo + "_" + idLayer))
                                    document.querySelector("#popover-extend-" + tema + "_" + grupo + "_" + idLayer).classList.remove("show");

                                existeLayer.forEach((itemLayer, i) => {
                                    Mapa.Map.removeLayer(itemLayer);
                                    if (document.querySelector("#" + ide_leyenda)) {
                                        document.querySelector("#" + ide_leyenda).parentNode.remove();
                                        document.querySelector("#" + ide_leyendaContenido).remove();
                                    }
                                });

                                if (layer.isLayerExtra) {
                                    layer.isLayerExtra.forEach((item, i) => {
                                        ide_layer = "layer_" + tema + "_" + grupo + "_" + idLayer + "_" + item.ide;
                                        existeLayer = Mapa.getLayer(ide_layer)

                                        existeLayer.forEach((itemLayer, i) => {
                                            Mapa.Map.removeLayer(itemLayer);
                                        });

                                    });
                                }

                                document.querySelectorAll('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-fixed="0"] option').forEach(option => option.remove());
                                document.querySelectorAll('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-fixed="0"]').forEach(select => select.add(new Option("-- Seleccione --", "00", false, false)));
                            }
                        }

                    });
                });
            }

            //Opacidad
            Array.from(document.querySelectorAll(".clsOpacidad")).forEach((item, i) => {
                item.addEventListener("input", (e) => {
                    let Layer = document.querySelector("#range-" + e.target.dataset.ide);
                    if (Layer) {
                        let ide_layer = "layer_" + e.target.dataset.ide;
                        let existeLayer = Mapa.getLayer(ide_layer);
                        if (existeLayer) {
                            existeLayer.forEach((itemLayer, i) => {
                                let opacidad = e.target.value / 100;
                                itemLayer.setOpacity(opacidad);
                            })
                        }

                    }

                });
            });

            //Filtros
            Array.from(document.querySelectorAll(".clsFilter")).forEach((itemPopover, i) => {
                itemPopover.addEventListener('shown.bs.collapse', function(e) {

                })
            });

            
            //Filtros selectores
            Array.from(document.querySelectorAll(".clsLayerFiltro")).forEach((item, i) => {
                item.addEventListener("change", (e) => {
                    
                    let tema = e.target.dataset.tema;
                    let grupo = e.target.dataset.grupo;
                    let idLayer = e.target.dataset.layer;
                    let filtro = e.target.dataset.filtro; 
                    
                    let inxFilter = parseInt(filtro);
                    let layer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == idLayer);

                    let ide_layer = "layer_" + tema + "_" + grupo + "_" + idLayer;
                    let existeLayer = Mapa.getLayer(ide_layer);
                    let Filter = layer.Extend.Filtros[inxFilter];

                    for (var f = (inxFilter + 1); layer.Extend.Filtros.length > f; f++) {
                        document.querySelectorAll('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + f + '"][data-fixed="0"] option').forEach(option => option.remove());
                        //document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + f + '"][data-fixed="0"]').add(new Option("Procesando...", "0", false, false));
                    }


                    let accion = "";
                    if (layer.Extend.Filtros.length == 0){

                    }else{
                        if (inxFilter == 0) {
                            if (Filter.accion) {         
                                if (Filter.accion == "Select") {
                                    accion = "Select";
                                }else{
                                    let NextFiltro = (inxFilter + 1)
                                    let isNext = document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + NextFiltro + '"]')
                                    if (isNext){
                                        Filter = layer.Extend.Filtros[NextFiltro]; 
                                        //Valido si es fija y si tiene evento Select
                                        if (Filter.isFixed){
                                            document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + NextFiltro + '"]').dispatchEvent(new Event("change"));
                                            return
                                        }else{
                                            isNext.dispatchEvent(new Event("change"));
                                            return;
                                        }                                                            
                                    }else{
                                        if (Filter.accion) {      
                                            let isElement = parseInt(document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFilter).value);                                    
                                            if (isElement == 0){
                                                document.querySelector("#Mapa-Inicial").click();
                                                accion = "Active";
                                            }else{
                                                if (Filter.accion == "Select") {
                                                    accion = "Select";
                                                }
                                            }
                                            
                                        }else{
                                            accion = "Active";
                                        }
        
                                    }
                                }
                            }else{
                                let NextFiltro = (inxFilter + 1)
                                let isNext = document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + NextFiltro + '"]')
                                if (isNext){
                                    Filter = layer.Extend.Filtros[NextFiltro]; 
                                    //Valido si es fija y si tiene evento Select
                                    if (Filter.isFixed){
                                        document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + NextFiltro + '"]').dispatchEvent(new Event("change"));
                                        return
                                    }else{
                                        isNext.dispatchEvent(new Event("change"));
                                        return;
                                    }                                                            
                                }else{
                                    if (Filter.accion) {      
                                        let isElement = parseInt(document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFilter).value);                                    
                                        if (isElement == 0){
                                            document.querySelector("#Mapa-Inicial").click();
                                            accion = "Active";
                                        }else{
                                            if (Filter.accion == "Select") {
                                                accion = "Select";
                                            }
                                        }                                        
                                    }else{
                                        accion = "Active";
                                    }    
                                }
                            }
                            
                        }else{
                            let isElement = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFilter).options.length;
                            if (isElement < 2){
                                accion = "fetch";                                
                            }else{                                
                                if (Filter.accion) {         
                                    if (Filter.accion == "Select") {
                                        accion = "Select";
                                    } 
                                }else{
                                    if (Filter.isFixed){
                                        if (document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + (inxFilter + 1) + '"]'))
                                        {
                                            document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + (inxFilter + 1) + '"]').dispatchEvent(new Event("change"));
                                            return;
                                        }else{
                                            accion = "Active";
                                        }
                                        
                                    }else{
                                        if (document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + (inxFilter + 1) + '"]'))
                                        {
                                            document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' + (inxFilter + 1) + '"]').dispatchEvent(new Event("change"));
                                            return;
                                        }else{
                                            accion = "Active";
                                        }
                                    }                                    
                                }
                            }                          
                        }
                    }
                    

                    if (accion == "fetch"){
                        if (Filter.isFixed){
                            document.querySelector('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + idLayer + '"][data-filtro="' +  (inxFilter + 1) + '"]').dispatchEvent(new Event("change"));
                            return;
                        }else{                                                    
                            let inxFiltro = 0;
                            let params = {};
                            for (var item in Filter.params) {
                                if (Filter.params[item] == "LayerFiltro") {
                                    if (document.querySelector("#" + Filter.params[item] + "-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFiltro)) {
                                        params[item] = document.querySelector("#" + Filter.params[item] + "-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFiltro).value;
                                        inxFiltro++;
                                    }
                                } else {
                                    try {
                                        if (document.querySelector("#" + Filter.params[item])) {
                                            params[item] = document.querySelector("#" + Filter.params[item]).value;
                                        } else {
                                            params[item] = Filter.params[item];
                                        }

                                    } catch (ex) {
                                        params[item] = Filter.params[item];
                                    }
                                }
                            }

                            let endpoint = Mapa.BasePath + Filter.url;
                            let getParams = Mapa.getFormData(params);

                            fetch(endpoint, {
                                method: "POST",
                                body: getParams,
                            })
                            .then((response) => {
                                return response.text();
                            })
                            .then((response) => {
                                let obj = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFilter);
                                if (obj) {
                                    obj.innerHTML = (response.trim() == "" ? '<option value="0">-- Seleccione --</option>' : response);
                                    let cboNextFilter = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + (inxFilter + 1));
                                    if (cboNextFilter) {
                                        cboNextFilter.dispatchEvent(new Event("change"));
                                    } else {
                                        if (existeLayer.length == 0) {
                                            if (document.querySelector("#layer_" + tema + "_" + grupo + "_" + idLayer).checked) {
                                                Mapa.addLayer(tema, grupo, layer);
                                            }
                                        } else {
                                            Mapa.Map.removeLayer(existeLayer[0]);
                                            Mapa.addLayer(tema, grupo, layer);
                                        }
                                    }
                                }
                            }).catch((error) => {
                                console.error('load', error);
                            });
                        }
                    }

                    if (accion == "Select"){
                        let select = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + inxFilter + " option:checked");
                                if (select) {
                                    if (parseInt(select.value) == "0") {
                                        if (existeLayer.length == 0) {
                                            if (document.querySelector("#layer_" + tema + "_" + grupo + "_" + idLayer).checked) {
                                                Mapa.addLayer(tema, grupo, layer);
                                            }
                                        } else {
                                            Mapa.Map.removeLayer(existeLayer[0]);
                                            Mapa.addLayer(tema, grupo, layer);
                                        }

                                        document.querySelector("#Mapa-Inicial").click();
                                    } else {
                                        let lat = parseFloat(select.getAttribute('lat'));
                                        let lng = parseFloat(select.getAttribute('lng'));
    
                                        if (layer.isInfo) {
                                            if (layer.info) {
                                                
                                                if (layer.info.isZoom){
                                                    if (isNaN(lat)){
                                                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                                                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> La capa no se encuentra configurada correctamente';
                                                        Mapa.Mensaje.show();
                                                        return;
                                                    }
            
                                                    if (isNaN(lng)){
                                                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                                                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> La capa no se encuentra configurada correctamente';
                                                        Mapa.Mensaje.show();
                                                        return;
                                                    }
                                                }
    
                                                Mapa.Map.removeLayer(existeLayer[0]);
                                                Mapa.addLayer(tema, grupo, layer);

                                                if (layer.tipo == "wms") {
                                                    let params = {
                                                        capas: layer.info.capa,
                                                        latitud: lat,
                                                        longitud: lng,
                                                        id: 0
                                                    }
                                                    let endpoint = Mapa.BasePath + Mapa.Info;
                                                    let getParams = Mapa.getFormData(params);
                                                    document.querySelector(".preloader").style.display = "block";
                                                    fetch(endpoint, {
                                                            method: "POST",
                                                            body: getParams,
                                                        })
                                                        .then((response) => {
                                                            return response.text();
                                                        })
                                                        .then((response) => {
                                                            document.querySelector(".preloader").style.display = "none";
                                                            if (response) {
                                                                Mapa.BSOffcanvas.show()
                                                                document.querySelector("#Mapa-Contenido-Label").innerHTML = "Información";
                                                                $("#Mapa-Contenido-Contenedor").html($('<div id="info_ventana_poly">' + response + '</div>'));
                                                                
                                                                if (Mapa.MarkerSelected) {
                                                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                                                }
    
                                                                if (layer.info.isZoom){       
                                                                    Mapa.Map.setView([lat, lng], Mapa.MaxZoomPoligon);

                                                                    Mapa.MarkerSelected = L.marker([lat, lng], { icon: Mapa.IconFeature }).bindTooltip("Lugar consultado", {
                                                                        direction: 'center',
                                                                        permanent: false,
                                                                        noWrap: true,
                                                                        opacity: 0.75
                                                                    });
                                                                    Mapa.Map.addLayer(Mapa.MarkerSelected);                                                                    
                                                                }

                                                            } else {
                                                                
                                                                Mapa.BSOffcanvas.hide()
    
                                                                if (Mapa.MarkerSelected) {
                                                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                                                }
    
                                                            }
                                                        }).catch((error) => {
                                                            console.error(error);
    
                                                            if (Mapa.MarkerSelected) {
                                                                Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                                            }
    
                                                            Mapa.BSOffcanvas.hide()
                                                            document.querySelector(".preloader").style.display = "none";
                                                            console.error('load', error);
    
                                                        });
    
                                                }

                                                if (layer.tipo == "cluster") {
                                                    let label = select.text;
                                                    let params = {};
                                                    params["capa"] = layer.info.capa;
                                                    params[layer.info.key] = select.value;

                                                    let getParams = Mapa.getFormData(params);
                                                    let endpoint = Mapa.BasePath + layer.info.url;
                                                    document.querySelector(".preloader").style.display = "block";
                                                    fetch(endpoint, {
                                                            method: "POST",
                                                            body: getParams,
                                                        })
                                                        .then((response) => {
                                                            return response.text();
                                                        })
                                                        .then((response) => {
                                                            document.querySelector(".preloader").style.display = "none";
                                                            if (response) {
                                                                Mapa.BSOffcanvas.show();
                                                                document.querySelector("#Mapa-Contenido-Label").innerHTML = layer.nombre;                                
                                                                $("#Mapa-Contenido-Contenedor").html($(response));
                                
                                                                if (Mapa.MarkerSelected) {
                                                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                                                }
                                                                                        
                                                                //categoria viene el pop
                                                                let iconFeature = Mapa.IconFeature;
                                                                if (layer.info.iconSelected){
                                                                    if (layer.info.iconCategoria){
                                                                        let rangos = layer.icon.valores;
                                                                        let icon = rangos.find(resp => resp.id == categoria);
                                                                        
                                                                        iconFeature = L.icon({
                                                                            iconUrl: Mapa.BasePath + layer.info.iconSelected + icon.icon.split('/').pop().split('.')[0] + layer.info.iconCategoriaSelect, 
                                                                            iconSize: layer.icon.size,
                                                                            shadowSize: [60, 60],
                                                                            iconAnchor: [40, 40],
                                                                            shadowAnchor: [4, 4],
                                                                            popupAnchor: [-25, -40]
                                                                        });
                                                                    }else{
                                                                        iconFeature = L.icon({
                                                                            iconUrl: Mapa.BasePath + layer.info.iconSelected,
                                                                            iconSize: layer.icon.size,
                                                                            shadowSize: [60, 60],
                                                                            iconAnchor: [40, 40],
                                                                            shadowAnchor: [4, 4],
                                                                            popupAnchor: [-25, -40]
                                                                        });
                                                                    }                                                              
                                                                }                                                                                                                         

                                                                if (layer.info.isZoom){         
                                                                    Mapa.MarkerSelected = L.marker([lat, lng], { icon: iconFeature }).bindTooltip(label, {
                                                                        direction: 'center',
                                                                        permanent: false,
                                                                        noWrap: true,
                                                                        opacity: 0.75
                                                                    });
                                                                    Mapa.Map.addLayer(Mapa.MarkerSelected);

                                                                    Mapa.Map.setView([lat, lng], layer.info.zoom);
                                                                }
                                
                                                            } else {
                                
                                                                if (Mapa.MarkerSelected) {
                                                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                                                }
                                                                Mapa.BSOffcanvas.hide();
                                                            }
                                                        }).catch((error) => {
                                                            Mapa.BSOffcanvas.hide();
                                
                                                            document.querySelector(".preloader").style.display = "none";
                                                            console.error('load', error);
                                                        });
                                                        
                                                }
                                            }
                                        }
                                    }
                                }
                    }

                    if (accion == "Active"){
                        if (existeLayer.length == 0) {
                            if (document.querySelector("#layer_" + tema + "_" + grupo + "_" + idLayer).checked) {
                                Mapa.addLayer(tema, grupo, layer);
                            }
                        } else {
                            Mapa.Map.removeLayer(existeLayer[0]);
                            Mapa.addLayer(tema, grupo, layer);
                        }
                    }
                    
                });
            });

            //Agregamos eventos a botones
            Array.from(document.querySelectorAll(".clsBotones")).forEach((item, i) => {
                item.addEventListener("click", (e) => {
                    let tema = e.target.dataset.tema;
                    let grupo = e.target.dataset.grupo;
                    let idLayer = e.target.dataset.layer;
                    let boton = e.target.dataset.boton;

                    let inxBoton = parseInt(boton);
                    let layer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == idLayer);
                    let Boton = layer.Extend.Butones[inxBoton];

                    if (Boton.tipo == "estadistico") {
                        document.querySelector("#Mapa-Contenido-Label").innerHTML = "Estadísticas";
                        let endpoint = Mapa.BasePath + Boton.url;

                        let params = {};
                        let inxFiltro = 0;
                        for (var item in Boton.params) {

                            if (Boton.params[item] == "LayerFiltro") {
                                if (document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + layer.ide + "_" + inxFiltro)) {
                                    params[item] = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + layer.ide + "_" + inxFiltro).value;
                                    inxFiltro++;
                                }
                            } else {
                                try {
                                    if (document.querySelector("#" + Boton.params[item])) {
                                        params[item] = document.querySelector("#" + Boton.params[item]).value;
                                    } else {
                                        params[item] = Boton.params[item];
                                    }

                                } catch (ex) {
                                    params[item] = Boton.params[item];
                                }
                            }
                        }
                        let getParams = Mapa.getFormData(params);
                        document.querySelector(".preloader").style.display = "block";

                        fetch(endpoint, {
                                method: "POST",
                                body: getParams,
                            })
                            .then((response) => {
                                return response.text();
                            })
                            .then((response) => {
                                document.querySelector(".preloader").style.display = "none";
                                if (response) {
                                    setTimeout(() => {
                                        var bsOffcanvas = new bootstrap.Offcanvas(document.querySelector("#Mapa-Contenido"))
                                        bsOffcanvas.show()
                                        $("#Mapa-Contenido-Contenedor").html($(response));    
                                    }, 500);
                                    
                                }
                            }).catch((error) => {
                                document.querySelector(".preloader").style.display = "none";
                                console.error('load', error);
                            });
                    }

                });

            });    
            
        },

        addLayerExtra: (tema, grupo, idLayer, layer) => {
            let viewparams = [];
            let NivelDepartamento = document.querySelector("#Mapa-Filter-Departamento");
            let NivelProvincia = document.querySelector("#Mapa-Filter-Provincia");
            let NivelDistrito = document.querySelector("#Mapa-Filter-Distrito");

            if (NivelDistrito) {
                if (parseInt(NivelDistrito.value) > 0) {
                    viewparams.push("ubigeo:" + NivelDistrito.value);

                    viewparams.push("dpto:" + NivelDepartamento.value);
                    viewparams.push("prov:" + NivelProvincia.value);
                    viewparams.push("dist:" + NivelDistrito.value);

                } else if (parseInt(NivelProvincia.value) > 0) {
                    viewparams.push("ubigeo:" + NivelProvincia.value);

                    viewparams.push("dpto:" + NivelDepartamento.value);
                    viewparams.push("prov:" + NivelProvincia.value);
                } else if (parseInt(NivelDepartamento.value) > 0) {
                    viewparams.push("ubigeo:" + NivelDepartamento.value);

                    viewparams.push("dpto:" + NivelDepartamento.value);
                    viewparams.push("iddpto:" + NivelDepartamento.value);
                } else {

                }
            }

            if (layer.params) {
                layer.params.forEach((item, i) => {
                    let filter = document.querySelector(".clsLayerFiltro[data-tema='" + tema + "'][data-grupo='" + grupo + "'][data-layer='" + idLayer + "'][data-filtro='" + item.filter + "']");
                    if (filter) {
                        viewparams.push(item.viewparams + ":" + filter.value);
                    }
                });
            }

            if (layer.isChecked) {
                let filter = document.querySelector(".clsLayerFiltro[data-tema='" + tema + "'][data-grupo='" + grupo + "'][data-layer='" + idLayer + "'][data-filtro='" + layer.filter + "']");
                if (filter) {
                    if (filter.value == layer.value) {
                        var lyr =
                            L.tileLayer.wms(Mapa.ServerMap + layer.url, {
                                layers: layer.capas,
                                transparent: layer.transparente,
                                format: layer.formato,
                                tiled: true,
                                viewparams: (viewparams.length == 0 ? "" : viewparams.join(';'))
                            }).addTo(Mapa.Map);
                        lyr.setZIndex(layer.orden);
                        lyr.setOpacity((layer.opacidad / 100));
                        lyr.id = 'layer_' + tema + '_' + grupo + '_' + idLayer + "_" + layer.ide;
                        lyr.grupo = grupo;
                        lyr.tema = tema;
                        lyr.idLayer = idLayer;
                        lyr.idLayerExtra = layer.ide;
                    } else {
                        let ide_layer = "layer_" + tema + "_" + grupo + "_" + idLayer + "_" + layer.ide;
                        let existeLayer = Mapa.getLayer(ide_layer)
                        existeLayer.forEach((itemLayer, i) => {
                            Mapa.Map.removeLayer(itemLayer);
                        });
                    }
                }
            }
            if (!layer.isChecked) {

                let ide_layer = "layer_" + tema + "_" + grupo + "_" + idLayer + "_" + layer.ide;
                let existeLayer = Mapa.getLayer(ide_layer)
                existeLayer.forEach((itemLayer, i) => {
                    Mapa.Map.removeLayer(itemLayer);
                });

                var lyr =
                    L.tileLayer.wms(Mapa.ServerMap + layer.url, {
                        layers: layer.capas,
                        transparent: layer.transparente,
                        format: layer.formato,
                        tiled: true,
                        viewparams: (viewparams.length == 0 ? "" : viewparams.join(';'))
                    }).addTo(Mapa.Map);
                lyr.setZIndex(layer.orden);
                lyr.setOpacity((layer.opacidad / 100));
                lyr.id = 'layer_' + tema + '_' + grupo + '_' + idLayer + "_" + layer.ide;
                lyr.grupo = grupo;
                lyr.tema = tema;
                lyr.idLayer = idLayer;
                lyr.idLayerExtra = layer.ide;

            }
        },

        getFormData: function(object) {
            const formData = new FormData();
            Object.keys(object).forEach(key => formData.append(key, object[key]));
            return formData;
        },

        addLayer: (tema, grupo, layer) => {
 
            //Remove Leyendas del layer
            Array.from(document.querySelectorAll("#btn-tabLeyenda-" + tema + "_" + grupo + "_" + layer.ide)).forEach((item, i) => {
                document.querySelectorAll("#btn-tabLeyenda-" + tema + "_" + grupo + "_" + layer.ide)[i].parentNode.remove();
                document.querySelectorAll("#tabLeyenda-" + tema + "_" + grupo + "_" + layer.ide)[i].remove();
            });


            if (layer.tipo == "wms") {
                let viewparams = [];

                let NivelDepartamento = document.querySelector("#Mapa-Filter-Departamento");
                let NivelProvincia = document.querySelector("#Mapa-Filter-Provincia");
                let NivelDistrito = document.querySelector("#Mapa-Filter-Distrito");

                if (NivelDistrito) {
                    if (parseInt(NivelDistrito.value) > 0) {
                        viewparams.push("ubigeo:" + NivelDistrito.value);

                        viewparams.push("dpto:" + NivelDepartamento.value);
                        viewparams.push("iddpto:" + NivelDepartamento.value);

                        viewparams.push("prov:" + NivelProvincia.value);
                        viewparams.push("dist:" + NivelDistrito.value);

                    } else if (parseInt(NivelProvincia.value) > 0) {
                        viewparams.push("ubigeo:" + NivelProvincia.value);

                        viewparams.push("dpto:" + NivelDepartamento.value);
                        viewparams.push("iddpto:" + NivelDepartamento.value);

                        viewparams.push("prov:" + NivelProvincia.value);
                    } else if (parseInt(NivelDepartamento.value) > 0) {
                        viewparams.push("ubigeo:" + NivelDepartamento.value);

                        viewparams.push("dpto:" + NivelDepartamento.value);
                        viewparams.push("iddpto:" + NivelDepartamento.value);
                    } else {

                    }
                }

                Array.from(document.querySelectorAll(".clsLayerFiltro[data-tema='" + tema + "'][data-grupo='" + grupo + "'][data-layer='" + layer.ide + "']")).forEach((item, i) => {
                    if (item.value == "00" || item.value == "0") {

                    } else {
                        if (item.dataset.prefijo.trim() != "")
                            if (parseInt(item.value) > 0 || isNaN(parseInt(item.value)))
                                viewparams.push(item.dataset.prefijo + ":" + (item.dataset.split == "true" ? item.value.split('-')[0] : item.value));
                    }
                })

                if (layer.params) {
                    for (var item in layer.params) {
                        viewparams.push(item + ":" + layer.params[item]);
                    }
                }

                let opacidad = layer.opacidad;
                if (document.querySelector("#range-" + tema + "_" + grupo + "_" + layer.ide)) {
                    opacidad = parseInt(document.querySelector("#range-" + tema + "_" + grupo + "_" + layer.ide).value);
                }

                var lyr =
                    L.tileLayer.wms(Mapa.ServerMap + layer.url, {
                        layers: layer.capas,
                        transparent: layer.transparente,
                        format: layer.formato,
                        tiled: true,
                        viewparams: (viewparams.length == 0 ? "" : viewparams.join(';'))
                    }).addTo(Mapa.Map);
                lyr.setZIndex(layer.orden);
                lyr.setOpacity((opacidad / 100));
                lyr.id = 'layer_' + tema + '_' + grupo + '_' + layer.ide;
                lyr.grupo = grupo;
                lyr.tema = tema;
                lyr.idLayer = layer.ide;
                lyr.tipoLayer = layer.tipo;

                if (layer.isLeyenda) {
                    Mapa.addLeyenda(layer, tema, grupo);
                }

                if (layer.isLayerExtra) {
                    layer.isLayerExtra.forEach((item, i) => {
                        Mapa.addLayerExtra(tema, grupo, layer.ide, item);
                    });
                }
            }

            if (layer.tipo == "cluster") {
                let ide_layer = "layer_" + tema + "_" + grupo + "_" + layer.ide;
                let existeLayer = Mapa.getLayer(ide_layer);
                existeLayer.forEach((itemLayer, i) => {
                    Mapa.Map.removeLayer(itemLayer);
                });
                
                let endpoint = Mapa.BasePath + layer.url;

                let params = {};
                let inxFiltro = 0;
                for (var item in layer.params) {

                    if (layer.params[item] == "LayerFiltro") {
                        if (document.querySelector("#" + layer.params[item] + "-" + tema + "_" + grupo + "_" + layer.ide + "_" + inxFiltro)) {
                            params[item] = document.querySelector("#" + layer.params[item] + "-" + tema + "_" + grupo + "_" + layer.ide + "_" + inxFiltro).value;
                            inxFiltro++;
                        }
                    } else {
                        try {
                            if (document.querySelector("#" + layer.params[item])) {
                                params[item] = document.querySelector("#" + layer.params[item]).value;
                            } else {
                                params[item] = layer.params[item];
                            }

                        } catch (ex) {
                            params[item] = layer.params[item];
                        }
                    }
                }
                let getParams = Mapa.getFormData(params);
                document.querySelector(".preloader").style.display = "block";

                fetch(endpoint, {
                        method: "POST",
                        body: getParams,
                    })
                    .then((response) => {
                        return response.text();
                    })
                    .then((response) => {

                        document.querySelector(".preloader").style.display = "none";

                        if (response) {
                            let json = JSON.parse(response);

                            if (json.status == "warning") {
                                document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                                document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = json.msg;
                                Mapa.Mensaje.show();

                                document.querySelectorAll('.clsLayerFiltro[data-tema="' + tema + '"][data-grupo="' + grupo + '"][data-layer="' + layer.ide + '"][data-fixed="0"] option').forEach(option => option.remove());
                            }

                            if (json.status == "success") {
                                let lyr = L.markerClusterGroup({
                                    spiderfyOnMaxZoom: true,
                                    showCoverageOnHover: false,
                                    zoomToBoundsOnClick: true
                                });

                                json.data.forEach((item, i) => {
                                    if (layer.icon.type == "key") {
                                        var iconFeature = L.icon({
                                            iconUrl: Mapa.BasePath + layer.icon.pathIcon + (item[layer.icon.gid]).replace(/L/g, '') + "." + layer.icon.extIcon,
                                            iconSize: layer.icon.size,
                                            shadowSize: [60, 60],
                                            iconAnchor: [40, 40],
                                            shadowAnchor: [4, 4],
                                            popupAnchor: [-25, -40]
                                        });

                                        let marker = L.marker([parseFloat(item.latitud), parseFloat(item.longitud)], { icon: iconFeature }).bindTooltip(item[layer.icon.label], {
                                            direction: 'center',
                                            permanent: false,
                                            noWrap: true,
                                            opacity: 0.75
                                        });
                                        marker.ide = item[layer.icon.gid];
                                        marker.localLayer = layer;
                                        marker.label = item[layer.icon.label];
                                        marker.tema = tema;
                                        marker.grupo = grupo;

                                        marker.on('click', function(e) {
                                            Mapa.SelectMarker(e);
                                        });
                                        lyr.addLayer(marker);
                                    }

                                    if (layer.icon.type == "icon") {
                                        var iconFeature = L.icon({
                                            iconUrl: Mapa.BasePath + layer.icon.pathIcon,
                                            iconSize: layer.icon.size,
                                            shadowSize: [60, 60],
                                            iconAnchor: [40, 40],
                                            shadowAnchor: [4, 4],
                                            popupAnchor: [-25, -40]
                                        });

                                        let marker = L.marker([parseFloat(item.latitud), parseFloat(item.longitud)], { icon: iconFeature }).bindTooltip(item[layer.icon.label], {
                                            direction: 'center',
                                            permanent: false,
                                            noWrap: true,
                                            opacity: 0.75
                                        });
                                        marker.ide = item[layer.icon.gid];
                                        marker.localLayer = layer;
                                        marker.label = item[layer.icon.label];
                                        marker.tema = tema;
                                        marker.grupo = grupo;

                                        marker.on('click', function(e) {
                                            Mapa.SelectMarker(e);
                                        });
                                        lyr.addLayer(marker);
                                    }


                                    if (layer.icon.type == "range") {
                                        let rangos = layer.icon.valores;
                                        let valor = parseFloat(item[layer.icon.valor]);
                                        let icon = rangos.find(resp => valor >= resp.ini && valor <= resp.end);
                                        if (icon) {
                                            var iconFeature = L.icon({
                                                iconUrl: Mapa.BasePath + icon.icon,
                                                iconSize: layer.icon.size,
                                                shadowSize: [60, 60],
                                                iconAnchor: [40, 40],
                                                shadowAnchor: [4, 4],
                                                popupAnchor: [-25, -40]
                                            });
                                            let marker = L.marker([parseFloat(item.latitud), parseFloat(item.longitud)], { icon: iconFeature }).bindTooltip(item[layer.icon.label], {
                                                direction: 'center',
                                                permanent: false,
                                                noWrap: true,
                                                opacity: 0.75
                                            });
                                            marker.ide = item[layer.icon.gid];
                                            marker.localLayer = layer;
                                            marker.label = item[layer.icon.label];
                                            marker.tema = tema;
                                            marker.grupo = grupo;

                                            marker.on('click', function(e) {
                                                Mapa.SelectMarker(e);
                                            });
                                            lyr.addLayer(marker);
                                        } else {

                                            let marker = L.marker([parseFloat(item.latitud), parseFloat(item.longitud)]).bindTooltip(item[layer.icon.label], {
                                                direction: 'center',
                                                permanent: false,
                                                noWrap: true,
                                                opacity: 0.75
                                            });
                                            marker.ide = item[layer.icon.gid];
                                            marker.localLayer = layer;
                                            marker.label = item[layer.icon.label];
                                            marker.tema = tema;
                                            marker.grupo = grupo;

                                            marker.on('click', function(e) {
                                                Mapa.SelectMarker(e);
                                            });
                                            lyr.addLayer(marker);

                                        }
                                    }

                                    if (layer.icon.type == "categoria") {
                                        let rangos = layer.icon.valores;
                                        let valor = item[layer.icon.gid];
                                        let icon = rangos.find(resp => resp.id == valor);
                                        if (icon) {
                                            var iconFeature = L.icon({
                                                iconUrl: Mapa.BasePath + icon.icon,
                                                iconSize: layer.icon.size,
                                                shadowSize: [60, 60],
                                                iconAnchor: [40, 40],
                                                shadowAnchor: [4, 4],
                                                popupAnchor: [-25, -40]
                                            });

                                            let marker = L.marker([parseFloat(item.latitud), parseFloat(item.longitud)], { icon: iconFeature }).bindTooltip(item[layer.icon.label], {
                                                direction: 'center',
                                                permanent: false,
                                                noWrap: true,
                                                opacity: 0.75
                                            });
                                            marker.ide = item[layer.icon.gid];
                                            marker.localLayer = layer;
                                            marker.label = item[layer.icon.label];
                                            marker.tema = tema;
                                            marker.grupo = grupo;
                                            marker.icon = icon.icon;

                                            marker.on('click', function(e) {
                                                Mapa.SelectMarker(e);
                                            });
                                            lyr.addLayer(marker);

                                        } else {
                                            let marker = L.marker([parseFloat(item.latitud), parseFloat(item.longitud)]).bindTooltip(item[layer.icon.label], {
                                                direction: 'center',
                                                permanent: false,
                                                noWrap: true,
                                                opacity: 0.75
                                            });
                                            marker.ide = item[layer.icon.gid];
                                            marker.localLayer = layer;
                                            marker.label = item[layer.icon.label];
                                            marker.tema = tema;
                                            marker.grupo = grupo;

                                            marker.on('click', function(e) {
                                                Mapa.SelectMarker(e);
                                            });
                                            lyr.addLayer(marker);
                                        }
                                    }
                                });

                                Mapa.Map.addLayer(lyr);
                                lyr.setZIndex(layer.orden);
                                lyr.id = 'layer_' + tema + '_' + grupo + '_' + layer.ide;
                                lyr.grupo = grupo;
                                lyr.tema = tema;
                                lyr.idLayer = layer.ide;
                                lyr.tipoLayer = layer.tipo;

                                if (layer.isLeyenda) {
                                    Mapa.addLeyenda(layer, tema, grupo);
                                }

                                if (layer.isLayerExtra) {
                                    layer.isLayerExtra.forEach((item, i) => {
                                        Mapa.addLayerExtra(tema, grupo, layer.ide, item);
                                    });
                                }

                            }
                        }

                    }).catch((error) => {
                        document.querySelector(".preloader").style.display = "none";
                        console.error('load', error);
                    });
            }

        },

        addLeyenda: (layer, tema, grupo) => {
            let DataLayer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == layer.ide);
            if (layer.isLeyenda) {
                let Tab = '<li class="nav-item" role="presentation">';
                Tab += '<button class="nav-link ' + (document.querySelectorAll("#Mapa-Leyenda-Tab li").length == 0 ? " active " : " ") + ' rounded-0" id="btn-tabLeyenda-' + tema + "_" + grupo + "_" + layer.ide + '" data-bs-toggle="pill" data-bs-target="#tabLeyenda-' + tema + "_" + grupo + "_" + layer.ide + '" type="button" role="tab" aria-controls="tabLeyenda-' + tema + "_" + grupo + "_" + layer.ide + '" aria-selected="true" alt="' + layer.leyenda.titulo + '" title="' + layer.leyenda.titulo + '">' + (layer.leyenda.titulo.length > 20 ? layer.leyenda.titulo.substring(0, 20) + "..." : layer.leyenda.titulo) + '</button>';
                Tab += '</li>';

                $("#Mapa-Leyenda-Tab").append($(Tab));

                let TabContendido = '<div class="tab-pane  ' + (document.querySelectorAll("#Mapa-Leyenda-Tab-Contenido div").length == 0 ? " active " : " fade ") + ' p-3" id="tabLeyenda-' + tema + "_" + grupo + "_" + layer.ide + '" role="tabpanel" aria-labelledby="btn-tabLeyenda-' + tema + "_" + grupo + "_" + layer.ide + '">';
                TabContendido += '<p>';
                TabContendido += layer.leyenda.titulo;
                TabContendido += '</p>';
                TabContendido += '<table>';

                layer.leyenda.imagenes.forEach((item, i) => {
                    TabContendido += '<tr>';
                    TabContendido += '<td class="align-middle w-100">';
                    TabContendido += '<img class="icon" src="' + Mapa.BasePath + item.url + '" />';
                    TabContendido += item.titulo;
                    TabContendido += '</td>';
                    TabContendido += '</tr>';
                });

                TabContendido += '</table>';
                TabContendido += '</div>';
                $("#Mapa-Leyenda-Tab-Contenido").append($(TabContendido));
            }


            document.querySelectorAll("#Mapa-Leyenda-Tab li button").forEach((item, i) => {
                item.classList.remove("active");
                document.querySelector("#" + item.id.replace('btn-', '')).classList.remove("active");
                document.querySelector("#" + item.id.replace('btn-', '')).classList.remove("fade");
            });

            if (document.querySelectorAll("#Mapa-Leyenda-Tab li button").length > 0) {
                document.querySelectorAll("#Mapa-Leyenda-Tab li button")[document.querySelectorAll("#Mapa-Leyenda-Tab li button").length - 1].classList.add("active");
                document.querySelectorAll("#Mapa-Leyenda-Tab-Contenido div")[document.querySelectorAll("#Mapa-Leyenda-Tab li button").length - 1].classList.add("active");
            }

        },

        Maestros: () => {},

        Load: () => {
            
            let endpoint = Mapa.BasePath + "resources/config.json?id=" + (new Date()).getTime();
            fetch(endpoint, {
                    method: "GET",
                    headers: {
                        'Content-type': 'application/json',
                    }
                })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    Mapa.Layers = response;
                    Mapa.getLimites();

                }).catch((error) => {
                    console.error('load', error);
                });


        },

        getLimites: () => {
            let endpoint = Mapa.BasePath + "resources/distritos.json?id=" + (new Date()).getTime();
            fetch(endpoint, {
                    method: "GET",
                    headers: {
                        'Content-type': 'application/json',
                    }
                })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    Mapa.Limites = response;
                    Mapa.Fill();
                    Mapa.TOC(Mapa.Layers);
                    Mapa.OpenTipoDocumentos();
                    Mapa.OpenDocumentos();
                    Mapa.OpenDocumentosCategoria();
                }).catch((error) => {
                    console.error('load', error);
                });

        },

        Fill: () => {
            let Departamentos = alasql("SELECT iddpto, nombdep from ? GROUP BY iddpto, nombdep order by iddpto ", [Mapa.Limites.Distritos]);
            let Mapa_Filter_Departamento = document.querySelector("#Mapa-Filter-Departamento");
            let Mapa_Filter_Provincia = document.querySelector("#Mapa-Filter-Provincia");
            let Mapa_Filter_Distrito = document.querySelector("#Mapa-Filter-Distrito");

            if (Mapa_Filter_Departamento) {
                Mapa_Filter_Departamento.add(new Option("-- Seleccione --", "0", false, false));

                Departamentos.forEach((item, i) => {
                    var data = new Option(item.nombdep, item.iddpto, false, false);
                    Mapa_Filter_Departamento.add(data);
                });

                Mapa_Filter_Provincia.add(new Option("-- Seleccione --", "0", false, false));
                Mapa_Filter_Distrito.add(new Option("-- Seleccione --", "0", false, false));
            }

        },

        /* TIPO DOCUMENTOS*/
      OpenTipoDocumentos: () => {
        let Mapa_Tipo_Documentos = document.querySelector("#Mapa-Tipo-Documentos");
        if (Mapa_Tipo_Documentos) {
            Mapa_Tipo_Documentos.addEventListener('shown.bs.collapse', function (e) {
                document.querySelector("#Mapa-Tipo-Documentos-Nombre").value = '';
                Mapa.LoadTipoDocumentos();
            })
        }
    
        let Mapa_Tipo_Documentos_Buscar = document.querySelector("#Mapa-Tipo-Documentos-Buscar");
        if (Mapa_Tipo_Documentos_Buscar){
            Mapa_Tipo_Documentos_Buscar.addEventListener("click", (e) => {
                e.preventDefault();
                Mapa.LoadTipoDocumentos();
            });
        }
    
        let Mapa_Tipo_Documentos_Agregar = document.querySelector("#Mapa-Tipo-Documentos-Agregar");
        if (Mapa_Tipo_Documentos_Agregar) {
            Mapa_Tipo_Documentos_Agregar.addEventListener("click", e => {
                e.preventDefault();
                let Mapa_Tipo_Documentos_Edit = document.querySelector("#Mapa-Tipo-Documentos-Edit");       
                Mapa_Tipo_Documentos_Edit.dataset.modo = "insert";
                Mapa_Tipo_Documentos_Edit.dataset.ide = "0";
    
                document.querySelector("#Mapa-Tipo-Documentos-Edit-Nombre").value = "";
                document.querySelector("#Mapa-Tipo-Documentos-Edit-Abreviatura").value = '';


                
    
            });
        }
       
        let Mapa_Tipo_Documentos_Edit_Guardar = document.querySelector("#Mapa-Tipo-Documentos-Edit-Guardar");
        if (Mapa_Tipo_Documentos_Edit_Guardar){
            Mapa_Tipo_Documentos_Edit_Guardar.addEventListener("click", (e) => {
                e.preventDefault();
                
                let Mapa_Tipo_Documentos_Edit = document.querySelector("#Mapa-Tipo-Documentos-Edit");               
                let params = {
                    modo : Mapa_Tipo_Documentos_Edit.dataset.modo,
                    codigo: Mapa_Tipo_Documentos_Edit.dataset.ide,
                    nombre : document.querySelector("#Mapa-Tipo-Documentos-Edit-Nombre").value,
                    abreviatura : document.querySelector("#Mapa-Tipo-Documentos-Edit-Abreviatura").value,
                    estado : document.querySelector("#Mapa-Tipo-Documentos-Edit-Estado").value,

                }
    
                
                let Errores = [];
                
    
                if (params.nombre.trim() == ''){
                    Errores.push("* Ingrese el nombre del tipo de documentos");
                }

              
    
                if (Errores.length > 0){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = Errores.join('<br>');
                    Mapa.Mensaje.show();
                    return;
                }
                
                let endpoint = Mapa.BasePath + "Dgdp/guardartipodocumentos";
              
                fetch(endpoint, {
                    method: "POST",
                    body: JSON.stringify(params),
                })
                    .then((response) => {
                        return response.json();
                    })
                    .then((response) => {
                        if (response) {
                            if (response.status == "success") {
                                document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Mensaje";
                                document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                                Mapa.Mensaje.show();
                                document.querySelector("#Mapa-Tipo-Documentos-Edit-Cerrar").click();
                                Mapa.LoadTipoDocumentos();
    
                            } else {
                                document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                                document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                                Mapa.Mensaje.show();
                            }
        
                        }
        
                    }).catch((error) => {
                        console.error('load', error);
                    }); 

                });
            }
        },
    
        LoadTipoDocumentos: () => {
            let params = {
                nombre : document.querySelector("#Mapa-Tipo-Documentos-Nombre").value,
                estado : -1
            };
            let endpoint = Mapa.BasePath + "Dgdp/tipodocumentos";
            let getParams = Mapa.getFormData(params);
    
            fetch(endpoint, {
                method: "POST",
                body: getParams,
            })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    if (response) {
                        if (response.status == "success") {
                            
                            let Mapa_Tipo_Documentos_Tabla = document.querySelector("#Mapa-Tipo-Documentos-Tabla tbody");
                            Mapa_Tipo_Documentos_Tabla.innerHTML = '';
    
                            let html = "";
                            response.data.forEach((item, i) => {
                                html = '<tr>';
                                html += '<td>' + (i + 1) + '</td>'; // N° automático
                                html += '<td>' + item.snombre + '</td>';
                                html += '<td>' + item.sabreviatura + '</td>';
                                html += '<td>' + (parseInt(item.nidactivo) === 1 ? "Activo" : "Inactivo") + '</td>';
                                html += '<td style="cursor: pointer; text-align: center; color: cadetblue;" onClick="Mapa.EditTipoDocumentos(\'' + item.nidtdocumento + '\');" data-bs-toggle="collapse" href="#Mapa-Tipo-Documentos-Edit"><i class="fas fa-edit" title="Editar"></i> </td>';
                                html += '<td style="cursor: pointer; text-align: center; color: red;" onClick="Mapa.EstadoTipoDocumentos(\'' + item.nidtdocumento + '\');" ><i class="fas fa-remove" title="Activar/Inactivar"></i></td>';
                                html += '</tr>';
                                Mapa_Tipo_Documentos_Tabla.innerHTML += html;
    
                            });
                            document.getElementById('tipo-documentos-contador').innerText = response.data.length;
    
                        } else {
                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                            Mapa.Mensaje.show();
                        }
    
                    }
    
                }).catch((error) => {
                    console.error('load', error);
                });
        },
    
        EstadoTipoDocumentos: (nidtdocumento) => {
            Swal.fire({
                title: 'Confirmar',
                text: "Desea activar/inactivar el registro",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sí, realizar la acción', 
                cancelButtonText: 'Cancelar'
              }).then((result) => {
                if (result.isConfirmed) {
                    let params = {
                        nidtdocumento: nidtdocumento
                    };
        
                    let endpoint = Mapa.BasePath + "Dgdp/estadotipodocumentos";
                    let getParams = Mapa.getFormData(params);
        
                    fetch(endpoint, {
                        method: "POST",
                        body: getParams,
                    })
                        .then((response) => {
                            return response.json();
                        })
                        .then((response) => {
                            if (response) {
                                if (response.status == "success") {
                                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Mensaje";
                                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                                    Mapa.Mensaje.show();
                                    Mapa.LoadTipoDocumentos();
                                }
        
                            }
                        }).catch((error) => {
                            console.error('load', error);
                        });
         
    
                }
              }) 
    
     
        },
    
        EditTipoDocumentos: (ide) => {
            let Mapa_Tipo_Documentos_Edit = document.querySelector("#Mapa-Tipo-Documentos-Edit");
    
            let params = {
                nidtdocumento: ide
            };
    
            let endpoint = Mapa.BasePath + "Dgdp/vertipodocumentos";
            let getParams = Mapa.getFormData(params);
    
            fetch(endpoint, {
                method: "POST",
                body: getParams,
            })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    if (response) {
                        if (response.status == "success") {
                            Mapa_Tipo_Documentos_Edit.dataset.modo = "edit";
                            Mapa_Tipo_Documentos_Edit.dataset.ide = ide;
    
                            document.querySelector("#Mapa-Tipo-Documentos-Edit-Nombre").value = response.data.snombre;
                            document.querySelector("#Mapa-Tipo-Documentos-Edit-Abreviatura").value = response.data.sabreviatura;
                            document.querySelector("#Mapa-Tipo-Documentos-Edit-Estado").value = response.data.nidactivo;

     
                        } else {
                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                            Mapa.Mensaje.show();
                        }
    
                    }
                }).catch((error) => {
                    console.error('load', error);
                });
        },
    /*end */


/* DOCUMENTOS*/
OpenDocumentos: () => {
let Mapa_Documentos = document.querySelector("#Mapa-Documentos");
if (Mapa_Documentos) {
    Mapa_Documentos.addEventListener('shown.bs.collapse', function (e) {
        document.querySelector("#Mapa-Documentos-Nombre").value = '';
        Mapa.LoadDocumentos();
    })
}

let Mapa_Documentos_Buscar = document.querySelector("#Mapa-Documentos-Buscar");
if (Mapa_Documentos_Buscar){
    Mapa_Documentos_Buscar.addEventListener("click", (e) => {
        e.preventDefault();
        Mapa.LoadDocumentos();
    });
}

let Mapa_Documentos_Agregar = document.querySelector("#Mapa-Documentos-Agregar");
if (Mapa_Documentos_Agregar) {
    Mapa_Documentos_Agregar.addEventListener("click", e => {
        e.preventDefault();
        let Mapa_Documentos_Edit = document.querySelector("#Mapa-Documentos-Edit");       
        Mapa_Documentos_Edit.dataset.modo = "insert";
        Mapa_Documentos_Edit.dataset.ide = "0";

        document.querySelector("#Mapa-Documentos-Edit-NroDocumento").value = "";
        document.querySelector("#Mapa-Documentos-Edit-Nombre").value = "";
        document.querySelector("#Mapa-Documentos-Edit-Descripcion").value = '';

        cargarTipoDocumentos('#Mapa-Documentos-Edit-TipoDocumento').then(() => {
            document.querySelector("#Mapa-Documentos-Edit-TipoDocumento").value = ""; 
        });
        cargarTipoCategoria('#Mapa-Documentos-Edit-TipoCategoria').then(() => {
            document.querySelector("#Mapa-Documentos-Edit-TipoCategoria").value = "";
                });

    });
}

let Mapa_Documentos_Edit_Guardar = document.querySelector("#Mapa-Documentos-Edit-Guardar");
if (Mapa_Documentos_Edit_Guardar){
    Mapa_Documentos_Edit_Guardar.addEventListener("click", (e) => {
        e.preventDefault();
        
        let Mapa_Documentos_Edit = document.querySelector("#Mapa-Documentos-Edit");               
        let params = {
            modo : Mapa_Documentos_Edit.dataset.modo,
            codigo: Mapa_Documentos_Edit.dataset.ide,
            nrodocumento : document.querySelector("#Mapa-Documentos-Edit-NroDocumento").value,
            nombre :document.querySelector("#Mapa-Documentos-Edit-Nombre").value,
            descripcion : document.querySelector("#Mapa-Documentos-Edit-Descripcion").value,
            nidtdocumento : document.querySelector("#Mapa-Documentos-Edit-TipoDocumento").value,
            nidcategoria : document.querySelector("#Mapa-Documentos-Edit-TipoCategoria").value,
            estado : document.querySelector("#Mapa-Documentos-Edit-Estado").value,
        }

        
        let Errores = [];
        

        if (params.nombre.trim() == ''){
            Errores.push("* Ingrese el nombre");
        }

        
        if (params.descripcion.trim() == ''){
            Errores.push("* Ingrese la descripcion del documento");
        }
    


        if (Errores.length > 0){
            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = Errores.join('<br>');
            Mapa.Mensaje.show();
            return;
        }
        
   // Create FormData object
   let formData = new FormData();
   formData.append('modo', params.modo);
   formData.append('codigo', params.codigo);
   formData.append('nrodocumento', params.nrodocumento);
   formData.append('estado', params.estado);
   formData.append('descripcion', params.descripcion);
   formData.append('nombre', params.nombre);
   formData.append('nidtdocumento', params.nidtdocumento);
   formData.append('nidcategoria', params.nidcategoria);

   

   let fileInput = document.querySelector("#Mapa-Documentos-Edit-ArchivoInput");
   for (let i = 0; i < fileInput.files.length; i++) {
       formData.append('files[]', fileInput.files[i]);
   }

        let endpoint = Mapa.BasePath + "Dgdp/guardardocumentos";
      
        fetch(endpoint, {
            method: "POST",
            body: formData,
        })
            .then((response) => {
                return response.json();
            })
            .then((response) => {
                if (response) {
                    if (response.status == "success") {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Mensaje";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                        Mapa.Mensaje.show();
                        document.querySelector("#Mapa-Documentos-Edit-Cerrar").click();
                        Mapa.LoadDocumentos();

                    } else {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                        Mapa.Mensaje.show();
                    }

                }

            }).catch((error) => {
                console.error('load', error);
            }); 

        });
    }
},

LoadDocumentos: () => {
    let params = {
        descripcion : document.querySelector("#Mapa-Documentos-Nombre").value,
        estado : -1
    };
    let endpoint = Mapa.BasePath + "Dgdp/documentos";
    let getParams = Mapa.getFormData(params);

    fetch(endpoint, {
        method: "POST",
        body: getParams,
    })
        .then((response) => {
            return response.json();
        })
        .then((response) => {
            if (response) {
                if (response.status == "success") {
                    
                    let Mapa_Documentos_Tabla = document.querySelector("#Mapa-Documentos-Tabla tbody");
                    Mapa_Documentos_Tabla.innerHTML = '';

                    let html = "";
                    response.data.forEach((item, i) => {
                        html = '<tr>';
                        html += '<td>' + (i + 1) + '</td>'; 
                        html += '<td>' + item.snro_documento + '</td>';
                        html += '<td>' + item.nombredocumento + '</td>';
                        html += '<td>' + item.nombrecategoria + '</td>';
                        html += '<td style="cursor: pointer; text-align: center; color: cadetblue;" onClick="Mapa.EditDocumentos(\'' + item.niddocumento + '\');" data-bs-toggle="collapse" href="#Mapa-Documentos-Edit"><i class="fas fa-edit" title="Editar"></i> </td>';
                        html += '<td style="cursor: pointer; text-align: center; color: red;" onClick="Mapa.EstadoDocumentos(\'' + item.niddocumento + '\');" ><i class="fas fa-remove" title="Activar/Inactivar"></i></td>';
                        html += '<tr>';
                        Mapa_Documentos_Tabla.innerHTML += html;

                    });
                    document.getElementById('documentos-contador').innerText = response.data.length;

                } else {
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                    Mapa.Mensaje.show();
                }

            }

        }).catch((error) => {
            console.error('load', error);
        });
},

EstadoDocumentos: (niddocumento) => {
    Swal.fire({
        title: 'Confirmar',
        text: "Desea activar/inactivar el registro",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, realizar la acción', 
        cancelButtonText: 'Cancelar'
      }).then((result) => {
        if (result.isConfirmed) {
            let params = {
                niddocumento: niddocumento
            };

            let endpoint = Mapa.BasePath + "Dgdp/estadodocumentos";
            let getParams = Mapa.getFormData(params);

            fetch(endpoint, {
                method: "POST",
                body: getParams,
            })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    if (response) {
                        if (response.status == "success") {
                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Mensaje";
                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                            Mapa.Mensaje.show();
                            Mapa.LoadDocumentos();
                        }

                    }
                }).catch((error) => {
                    console.error('load', error);
                });
 

        }
      }) 


},

EditDocumentos: (ide) => {
    let Mapa_Documentos_Edit = document.querySelector("#Mapa-Documentos-Edit");

    let params = {
        niddocumento: ide
    };

    let endpoint = Mapa.BasePath + "Dgdp/verdocumentos";
    let getParams = Mapa.getFormData(params);

    fetch(endpoint, {
        method: "POST",
        body: getParams,
    })
        .then((response) => {
            return response.json();
        })
        .then((response) => {
            if (response) {
                if (response.status == "success") {
                    Mapa_Documentos_Edit.dataset.modo = "edit";
                    Mapa_Documentos_Edit.dataset.ide = ide;

                    document.querySelector("#Mapa-Documentos-Edit-NroDocumento").value = response.data.snro_documento;
                    document.querySelector("#Mapa-Documentos-Edit-Nombre").value = response.data.nombredocumento;
                    document.querySelector("#Mapa-Documentos-Edit-Descripcion").value = response.data.sdescrcipcion;
                   /* document.querySelector("#Mapa-Documentos-Edit-TipoDocumento").value = response.data.nombretipo;
                    document.querySelector("#Mapa-Documentos-Edit-TipoCategoria").value = response.data.nombrecategoria;*/
                    cargarTipoDocumentos('#Mapa-Documentos-Edit-TipoDocumento').then(() => {
                        document.querySelector("#Mapa-Documentos-Edit-TipoDocumento").value = response.data.nidtipo; 
                    });
                    cargarTipoCategoria('#Mapa-Documentos-Edit-TipoCategoria').then(() => {
                        document.querySelector("#Mapa-Documentos-Edit-TipoCategoria").value = response.data.nidcategoria;
                            });


                    

                    document.querySelector("#Mapa-Documentos-Edit-Estado").value = response.data.nidactivo;


                } else {
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                    Mapa.Mensaje.show();
                }
            }
        }).catch((error) => {
            console.error('load', error);
        });
},
/*end */


/*CATEGORIA*/
OpenDocumentosCategoria: () => {
let Mapa_Documentos_Categoria = document.querySelector("#Mapa-Documentos-Categoria");
if (Mapa_Documentos_Categoria) {
    Mapa_Documentos_Categoria.addEventListener('shown.bs.collapse', function (e) {
        document.querySelector("#Mapa-Documentos-Categoria-Nombre").value = '';
        Mapa.LoadDocumentosCategoria();
    })
}

let Mapa_Documentos_Categoria_Buscar = document.querySelector("#Mapa-Documentos-Categoria-Buscar");
if (Mapa_Documentos_Categoria_Buscar){
    Mapa_Documentos_Categoria_Buscar.addEventListener("click", (e) => {
        e.preventDefault();
        Mapa.LoadDocumentosCategoria();
    });
}

let Mapa_Documentos_Categoria_Agregar = document.querySelector("#Mapa-Documentos-Categoria-Agregar");
if (Mapa_Documentos_Categoria_Agregar) {
    Mapa_Documentos_Categoria_Agregar.addEventListener("click", e => {
        e.preventDefault();
        let Mapa_Documentos_Categoria_Edit = document.querySelector("#Mapa-Documentos-Categoria-Edit");       
        Mapa_Documentos_Categoria_Edit.dataset.modo = "insert";
        Mapa_Documentos_Categoria_Edit.dataset.ide = "0";

        document.querySelector("#Mapa-Documentos-Categoria-Edit-Nombre").value = "";


    

    });
}

let Mapa_Documentos_Categoria_Edit_Guardar = document.querySelector("#Mapa-Documentos-Categoria-Edit-Guardar");
if (Mapa_Documentos_Categoria_Edit_Guardar){
    Mapa_Documentos_Categoria_Edit_Guardar.addEventListener("click", (e) => {
        e.preventDefault();
        
        let Mapa_Documentos_Categoria_Edit = document.querySelector("#Mapa-Documentos-Categoria-Edit");               
        let params = {
            modo : Mapa_Documentos_Categoria_Edit.dataset.modo,
            codigo: Mapa_Documentos_Categoria_Edit.dataset.ide,
            nombre : document.querySelector("#Mapa-Documentos-Categoria-Edit-Nombre").value,
            estado : document.querySelector("#Mapa-Documentos-Categoria-Edit-Estado").value,

        }

        
        let Errores = [];
        

        if (params.nombre.trim() == ''){
            Errores.push("* Ingrese el nombre de la categoria");
        }


        if (Errores.length > 0){
            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = Errores.join('<br>');
            Mapa.Mensaje.show();
            return;
        }
        
        let endpoint = Mapa.BasePath + "Dgdp/guardarcategorias";
              
        fetch(endpoint, {
            method: "POST",
            body: JSON.stringify(params),
        })
            .then((response) => {
                return response.json();
            })
            .then((response) => {
                if (response) {
                    if (response.status == "success") {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Mensaje";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                        Mapa.Mensaje.show();
                        document.querySelector("#Mapa-Documentos-Categoria-Edit-Cerrar").click();
                        Mapa.LoadDocumentosCategoria();

                    } else {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                        Mapa.Mensaje.show();
                    }

                }

            }).catch((error) => {
                console.error('load', error);
            }); 

        });
    }
},

LoadDocumentosCategoria: () => {
    let params = {
        nombre : document.querySelector("#Mapa-Documentos-Categoria-Nombre").value,
        estado : -1
    };
    let endpoint = Mapa.BasePath + "Dgdp/categorias";
    let getParams = Mapa.getFormData(params);

    fetch(endpoint, {
        method: "POST",
        body: getParams,
    })
        .then((response) => {
            return response.json();
        })
        .then((response) => {
            if (response) {
                if (response.status == "success") {
                    
                    let Mapa_Documentos_Categoria_Tabla = document.querySelector("#Mapa-Documentos-Categoria-Tabla tbody");
                    Mapa_Documentos_Categoria_Tabla.innerHTML = '';

                    let html = "";
                    response.data.forEach((item, i) => {
                        html = '<tr>';
                        html += '<td>' + (i + 1) + '</td>'; // N° automático
                        html += '<td>' + item.snombre + '</td>';
                        html += '<td>' + (parseInt(item.nidactivo) === 1 ? "Activo" : "Inactivo") + '</td>';
                        html += '<td style="cursor: pointer; text-align: center; color: cadetblue;" onClick="Mapa.EditDocumentosCategoria(\'' + item.nidcategoria + '\');" data-bs-toggle="collapse" href="#Mapa-Documentos-Categoria-Edit"><i class="fas fa-edit" title="Editar"></i> </td>';
                        html += '<td style="cursor: pointer; text-align: center; color: red;" onClick="Mapa.EstadoDocumentosCategoria(\'' + item.nidcategoria + '\');" ><i class="fas fa-remove" title="Activar/Inactivar"></i></td>';
                        html += '<tr>';
                        Mapa_Documentos_Categoria_Tabla.innerHTML += html;

                    });
                    document.getElementById('documentos-categoria-contador').innerText = response.data.length;

                } else {
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                    Mapa.Mensaje.show();
                }

            }

        }).catch((error) => {
            console.error('load', error);
        });
},

EstadoDocumentosCategoria: (nidcategoria) => {
    Swal.fire({
        title: 'Confirmar',
        text: "Desea activar/inactivar el registro",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, realizar la acción', 
        cancelButtonText: 'Cancelar'
      }).then((result) => {
        if (result.isConfirmed) {
            let params = {
                nidcategoria: nidcategoria
            };

            let endpoint = Mapa.BasePath + "Dgdp/estadocategorias";
            let getParams = Mapa.getFormData(params);

            fetch(endpoint, {
                method: "POST",
                body: getParams,
            })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    if (response) {
                        if (response.status == "success") {
                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Mensaje";
                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                            Mapa.Mensaje.show();
                            Mapa.LoadDocumentosCategoria();
                        }

                    }
                }).catch((error) => {
                    console.error('load', error);
                });
 

        }
      }) 


},

EditDocumentosCategoria: (ide) => {
    let Mapa_Documentos_Categoria_Edit = document.querySelector("#Mapa-Documentos-Categoria-Edit");

    let params = {
        nidcategoria: ide
    };

    let endpoint = Mapa.BasePath + "Dgdp/vercategorias";
    let getParams = Mapa.getFormData(params);

    fetch(endpoint, {
        method: "POST",
        body: getParams,
    })
        .then((response) => {
            return response.json();
        })
        .then((response) => {
            if (response) {
                if (response.status == "success") {
                    Mapa_Documentos_Categoria_Edit.dataset.modo = "edit";
                    Mapa_Documentos_Categoria_Edit.dataset.ide = ide;

                    document.querySelector("#Mapa-Documentos-Categoria-Edit-Nombre").value = response.data.snombre;
                    document.querySelector("#Mapa-Documentos-Categoria-Edit-Estado").value = response.data.nidactivo;


                } else {
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                    Mapa.Mensaje.show();
                }

            }
        }).catch((error) => {
            console.error('load', error);
        });
},
/*end fin*/
/*login*/
Login: () => {
let params = {
    usuario: document.querySelector("#Mapa-Login-Usuario").value,
    clave: document.querySelector("#Mapa-Login-Clave").value,
    idproyecto: 5  // agregamos idproyecto fijo para tu caso
};

let endpoint = Mapa.BasePath + "Dgdp/login";
let getParams = Mapa.getFormData(params);

fetch(endpoint, {
    method: "POST",
    body: getParams,
})
    .then(response => response.json())
    .then(response => {
        if (response) {
            if (response.status == 200) {
                window.location.reload(); // recarga la página para actualizar estado login
            } else {
                document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
                document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = response.msg;
                Mapa.Mensaje.show();
            }
        } else {
            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Advertencia";
            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = "Los datos no son validos";
            Mapa.Mensaje.show();
        }
    }).catch(error => {
        console.error('load', error);
    });
},


Logout: () => {
Swal.fire({
    title: 'Confirmar',
    text: "Desea salir de la sesión",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#3085d6',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Sí, salir de la sesión',
    cancelButtonText: 'Cancelar'
}).then((result) => {
    if (result.isConfirmed) {
        let endpoint = Mapa.BasePath + "Dgdp/logout";
        fetch(endpoint, { method: 'POST' })
            .then(res => res.json())
            .then(res => {
                if (res.response === "OK") {
                    window.location.reload();
                } else {
                    alert("Error al cerrar sesión");
                }
            });
    }
});
},

/*end */
        Events: () => {    
            
        /**Inicio Descargar */
        let Mapa_Download = document.querySelector("#Mapa-Download");
        if (Mapa_Download){
            Mapa_Download.addEventListener('hidden.bs.collapse', function(e) {
                let layers = Mapa.getLayer("LayerDownload");
                layers.forEach(item => {
                    Mapa.Map.removeLayer(item);
                });
                Mapa.Ambito = null;

                Mapa.Map.TypeDrawing = null;
                Mapa.Map.editTools.stopDrawing();
            });

            Mapa_Download.addEventListener('shown.bs.collapse', function(e) {
                Mapa.Ambito = null;

                Mapa.Map.TypeDrawing = null;
                Mapa.Map.editTools.stopDrawing();
            })
        } 

        let Mapa_Download_Procesar = document.querySelector("#Mapa-Download-Procesar");
        if (Mapa_Download_Procesar){
            Mapa_Download_Procesar.addEventListener("click", (e) => {
                e.preventDefault();
                let modo = document.querySelector("[name='Mapa-Download-Modo']:checked").value;
                if (modo == "Dibujo"){
                    let layers = Mapa.getLayer("LayerDownload");
                    if (!layers){
                        Mapa.Ambito = null;
                    }else{
                        Mapa.Ambito = Mapa.getLayer("LayerDownload")[0].getLatLngs()[0];
                    }
                }

                if (modo == "shape"){
                    let layers = Mapa.getLayer("LayerDownload");
                    if (!layers){
                        Mapa.Ambito = null;
                    }else{
                        for (layer in Mapa.getLayer("LayerDownload")[0]._layers){
                            Mapa.Ambito = Mapa.getLayer("LayerDownload")[0]._layers[layer].getLatLngs()[0];
                        }
                    }
                }

                if (!Mapa.Ambito){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> No se ha establecido un ámbito de descarga';
                    Mapa.Mensaje.show();                        
                    return;
                }

                if (document.querySelectorAll(".clsLayers:checked").length ==0){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> No hay capas activas';
                    Mapa.Mensaje.show();                        
                    return;
                }

                let area = L.GeometryUtil.geodesicArea(Mapa.Ambito);
                if (area > Mapa.AreaDescargar){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Lo sentimos el ámbito dibujado es mayor al soportado, el ámbito no debe ser mayor a ' + new Intl.NumberFormat('es-PE').format(parseFloat( Mapa.AreaDescargar)) + ' m2 <br>El ámbito dibujado es de ' + new Intl.NumberFormat('es-PE').format(parseFloat( area)) + ' m2' ;
                    Mapa.Mensaje.show();                        
                    return;
                }

                let geom = Mapa.Ambito.map(resp => [resp.lng, resp.lat] ).join(';').replaceAll(',', ' ').replaceAll(';', ',') + "," + Mapa.Ambito.map(resp => [resp.lng, resp.lat] )[0].join(' ');
                let capas = Array.from(document.querySelectorAll(".clsLayers[data-download='1']:checked")).map(resp => resp.dataset.idendownload).join();

                if (capas == ""){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> No hay capas activas';
                    Mapa.Mensaje.show();                        
                    return;
                }

                let filter = [];
                Array.from(document.querySelectorAll(".clsLayers[data-download='1']:checked")).forEach((item, i) => {
                    let tema = item.dataset.tema;
                    let grupo = item.dataset.grupo;
                    let idLayer = item.dataset.layer;
                    let layer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == idLayer);

                    if (layer.isDownloadFilter){
                        let filtros = [];
                        if (layer.isDownloadFilter.ubigeos){
                            layer.isDownloadFilter.ubigeos.forEach((Ubigeo, iUbigeo) => {
                                filtros.push(document.querySelector(Ubigeo).value);
                            });
                        }
                        
                        if (layer.isDownloadFilter.filtros){
                            layer.isDownloadFilter.filtros.forEach((Filter, iFilter) => {
                                let obj = "#LayerFiltro-"+ tema + "_" + grupo + "_" + idLayer + "_" + Filter;
                                filtros.push(document.querySelector(obj).value);
                            });
                        }

                        if (layer.isDownloadFilter.extras){
                            layer.isDownloadFilter.extras.forEach((Extra, iExtra) => {
                                filtros.push(Extra);
                            });
                        }
                        
                        filter.push(filtros.join(';'))
                    }else{
                        filter.push("")
                    }
                });

                let params = {
                    capas  : capas,
                    geom : geom,
                    filtros : filter.join('|'),
                }

                let endpoint = Mapa.BasePath + Mapa.Download;
                let getParams = Mapa.getFormData(params);
                let tipo = document.querySelector("[name='Mapa-Download-Formato']:checked").value;
                document.querySelector(".preloader").style.display = "block";

                fetch(endpoint, {
                    method: "POST",
                    body: getParams,
                })
                .then((response) => {
                    return response.json();
                })
                .then((response) => {
                    document.querySelector(".preloader").style.display = "none";

                    if (response){
                        for (item in response){
                            let data = response[item];                                
                            let name = "";
                            if (tipo == "json"){
                                name = item + ".geojson";
                                var blob = new Blob([data], {type: 'application/geo+json'});
                                saveAs(blob, name);                                                              
                            }                              

                            if (tipo == "kml"){
                                name = item + ".kml";     
                                var blob = new Blob([tokml(JSON.parse(data), {
                                    name: item,
                                    description: item
                                })], { type: 'application/vnd.google-earth.kml+xml' });
                                saveAs(blob, name);                                                              
                            }      
                            
                            if (tipo == "shp"){
                                var options = {
                                    folder: item,
                                    types: {
                                     point: item,
                                     polygon: item,
                                     line: item
                                    }
                                   }
                                shpwrite.download(JSON.parse(data), options);                                            
                            }   
                        }
                    }
                      
                }).catch((error) => {                         
                    console.error('load', error);
                    document.querySelector(".preloader").style.display = "none";
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> La acción no pudo ser procesada';
                    Mapa.Mensaje.show();
                   
                });
            });
        }

        let Mapa_Download_File = document.querySelector("#Mapa-Download-File");
        if (Mapa_Download_File){
            Mapa_Download_File.addEventListener("change", (e) => {
                var file = document.querySelector("#Mapa-Download-File");
               
                var reader = new FileReader();
                reader.onload = function() {
                    if (reader.readyState != 2 || reader.error) {
                        console.error(reader);
                        return;
                    } else {
                        shp(reader.result).then(function(geojson) {
                           
                            if (geojson){
                                if (geojson.features){
                                    if (geojson.features.length > 1){
                                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> El archivo no debe contener mas de 1 polígono';
                                        Mapa.Mensaje.show();

                                        document.querySelector("#Mapa-Download-File").value ="";
                                        return;
                                    }else{
                                        if (geojson.features[0].geometry.type != "Polygon"){
                                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Lo sentimos el tipo de geometría no es valido';
                                            Mapa.Mensaje.show();

                                            document.querySelector("#Mapa-Download-File").value =""; 
                                            return;
                                        }
                                    }
                                }
                            }                                                        

                            let layerTemp = L.geoJSON(geojson, {                                    
                                onEachFeature: function(feature, layer) {                                    
                                    Mapa.Ambito = feature;
                                }
                            });

                            layerTemp.id = "LayerDownload";
                            Mapa.Map.addLayer(layerTemp);
                            let box = layerTemp.getBounds();
                            Mapa.Map.fitBounds(box);
                            Mapa.Zoom = Mapa.Map._zoom;
                        });
                    }
                }
                reader.readAsArrayBuffer(file.files[0])
                
            });
        }
                 
        let Mapa_Download_Establecer = document.querySelector("#Mapa-Download-Establecer");
        if (Mapa_Download_Establecer){
            Mapa_Download_Establecer.addEventListener("click", (e) => {
                e.preventDefault();
                
                let layers = Mapa.getLayer("LayerDownload");
                layers.forEach(item => {
                    Mapa.Map.removeLayer(item);
                });
                Mapa.Ambito = null;

                let modo = document.querySelector("[name='Mapa-Download-Modo']:checked").value;
                if (modo == "Dibujo"){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Modo de dibujo";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-info-circle colorInfo"></i> Ya puede dibujar el ámbito de descarga en el mapa';
                    Mapa.Mensaje.show();

                    Mapa.Map.TypeDrawing = "poly";
                    Mapa.Map.editTools.startPolygon(null, { id: "LayerDownload" });
                }

                if (modo == "Shape"){
                    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Modo de dibujo";
                    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-info-circle colorInfo"></i> Seleccione el archivo shapefile del ámbito de descarga en el mapa';
                    Mapa.Mensaje.show();
                    document.querySelector("#Mapa-Download-File").click();
                }

              
            })
        } 
                    
        /**Fin Descargar */            

            let Mapa_Leyenda = document.querySelector("#Mapa-Leyenda");
            if (Mapa_Leyenda){
                Mapa_Leyenda.addEventListener('shown.bs.collapse', function(e) {
                    setTimeout(() => {
                        $("#Mapa-Leyenda").collapse('hide');
                    }, 10000);
                    
                })
            } 

            let Mapa_Contacto = document.querySelector("#Mapa-Contacto");
            if (Mapa_Contacto){
                Mapa_Contacto.addEventListener('shown.bs.collapse', function(e) {
                    grecaptcha.reset(0);
                    document.querySelector("#Mapa-Contacto-Nombre").value = "";
                    document.querySelector("#Mapa-Contacto-Correo").value = "";
                    document.querySelector("#Mapa-Contacto-Asunto").value = "";
                    document.querySelector("#Mapa-Contacto-Mensaje").value = "";
                })
            } 

            let Mapa_Contenido = document.querySelector("#Mapa-Contenido");
            if (Mapa_Contenido) {
                Mapa_Contenido.addEventListener('hide.bs.offcanvas', function(e) {
                    if (Mapa.MarkerSelected) {
                        Mapa.Map.removeLayer(Mapa.MarkerSelected);
                    }
                })
            }
    

            $('.item-tool-draw').click(function(e) {
                e.preventDefault();

                if (document.querySelector("#Mapa-Dibujar i").dataset.activo == 1) {
                    let tool = e.target.dataset.tool;
                    Mapa.Map.TypeDrawing = tool;
                    switch (tool) {
                        case 'point':
                            Mapa.Map.editTools.startMarker(null, { id: "temporal_draw" });
                            break;

                        case 'line':
                            Mapa.Map.editTools.startPolyline(null, { id: "temporal_draw" });
                            break;

                        case 'poly':
                            Mapa.Map.editTools.startPolygon(null, { id: "temporal_draw" });
                            break;

                        case 'remove':
                            $('.leaflet-container').css('cursor', 'default');
                            break;
                    }
                }

            });

            let Mapa_Dibujar = document.querySelector("#Mapa-Dibujar");
            if (Mapa_Dibujar) {
                Mapa_Dibujar.addEventListener("click", (e) => {
                    e.preventDefault();

                    setTimeout(() => {
                        if ($("#btn-linea").is(':visible')) {
                            e.target.dataset.activo = 1;
                            //Activo Herramientas
                        } else {
                            e.target.dataset.activo = 0;
                            Mapa.Map.TypeDrawing = null;
                            Mapa.Map.editTools.stopDrawing();

                            let existeLayer = Mapa.getLayer("temporal_draw");
                            existeLayer.forEach((itemLayer, i) => {
                                Mapa.Map.removeLayer(itemLayer);
                            });
                            //Inactivo Herramientas
                        }
                    }, 500);
                });
            }

            Mapa.Map.on("click", (e) => {
                L.DomEvent.stopPropagation(e);

                if (parseInt(document.querySelector("#Mapa-Medir >i").dataset.activo) == 1) {
                    console.log('Herramienta de medicion activa');
                    return;
                }

                if (parseInt(document.querySelector("#Mapa-Dibujar i").dataset.activo) == 1) {
                    console.log('Herramienta de creación activa');
                    return;
                }

                if (Mapa.Map.TypeDrawing != null){
                    console.log('Herramienta de descarga activa');
                    return;
                }

                let capas = [];
                Array.from(document.querySelectorAll(".clsLayers:checked[data-type='wms']")).forEach((item, i) => {
                    let tema = item.dataset.tema;
                    let grupo = item.dataset.grupo;
                    let idLayer = item.dataset.layer;
                    let itemLayer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == idLayer);
                    if (itemLayer.isInfo) {
                        if (itemLayer.info) {
                            capas.push(itemLayer.info.capa);
                        }
                    }
                });

                if (capas.length > 0) {
                    let params = {
                        capas: capas.join(),
                        latitud: e.latlng.lat,
                        longitud: e.latlng.lng,
                        id: 0
                    }

                    let endpoint = Mapa.BasePath + Mapa.Info;
                    let getParams = Mapa.getFormData(params);
                    document.querySelector(".preloader").style.display = "block";
                    fetch(endpoint, {
                            method: "POST",
                            body: getParams,
                        })
                        .then((response) => {
                            return response.text();
                        })
                        .then((response) => {
                            document.querySelector(".preloader").style.display = "none";
                            if (response) {
                                Mapa.BSOffcanvas.show()
                                document.querySelector("#Mapa-Contenido-Label").innerHTML = "Información";
                                $("#Mapa-Contenido-Contenedor").html($('<div id="info_ventana_poly">' + response + '</div>'));

                                
                                if (Mapa.MarkerSelected) {
                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                }

                                Mapa.Map.setView(e.latlng, Mapa.MaxZoomPoligon);

                                Mapa.MarkerSelected = L.marker(e.latlng, { icon: Mapa.IconFeature }).bindTooltip("Lugar consultado", {
                                    direction: 'center',
                                    permanent: false,
                                    noWrap: true,
                                    opacity: 0.75
                                });
                                Mapa.Map.addLayer(Mapa.MarkerSelected);


                            } else {
                                    Mapa.BSOffcanvas.hide()

                                if (Mapa.MarkerSelected) {
                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                }

                            }
                        }).catch((error) => {
                            console.error(error);

                            if (Mapa.MarkerSelected) {
                                Mapa.Map.removeLayer(Mapa.MarkerSelected);
                            }

                            Mapa.BSOffcanvas.hide()
                            document.querySelector(".preloader").style.display = "none";
                            console.error('load', error);

                        });


                }

            });

            let Mapa_BaseMap = document.querySelectorAll(".Mapa-BaseMap");
            if (Mapa_BaseMap) {
                Mapa_BaseMap.forEach((item, i) => {
                    item.addEventListener("click", (e) => {
                        e.preventDefault();

                        Mapa.Map.removeLayer(Mapa.MapBase["Satélite"]);
                        Mapa.Map.removeLayer(Mapa.MapBase["Callejero"]);
                        Mapa.Map.removeLayer(Mapa.MapBase["Topográfico"]);

                        let mapa = e.target.dataset.mapa;
                        if (mapa == "Satelite")
                            Mapa.Map.addLayer(Mapa.MapBase["Satélite"]);

                        if (mapa == "OSM")
                            Mapa.Map.addLayer(Mapa.MapBase["Callejero"]);

                        if (mapa == "Topo")
                            Mapa.Map.addLayer(Mapa.MapBase["Topográfico"]);

                        //document.querySelector(".leaflet-control-attribution").innerHTML = document.querySelector(".leaflet-control-attribution").innerHTML.substring(0, document.querySelector(".leaflet-control-attribution").innerHTML.indexOf('|'));
                    });
                });

            }

            let Mapa_Shape_Procesar = document.querySelector("#Mapa-Shape-Procesar");
            if (Mapa_Shape_Procesar) {
                Mapa_Shape_Procesar.addEventListener("click", (e) => {
                    e.preventDefault();

                    let Mapa_Shape_File = document.querySelector("#Mapa-Shape-File").value;
                    if (Mapa_Shape_File == "") {

                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> No se ha seleccionado un archivo valido';
                        Mapa.Mensaje.show();
                        return;
                    }

                    Mapa.LoadSHP();

                });
            }

            let Mapa_Shape_Limpiar = document.querySelector("#Mapa-Shape-Limpiar");
            if (Mapa_Shape_Limpiar) {
                Mapa_Shape_Limpiar.addEventListener("click", (e) => {
                    e.preventDefault();
                    let layers = Mapa.getLayer("LayerSHP");
                    layers.forEach(item => {
                        Mapa.Map.removeLayer(item);
                    });
                });
            }

            let Mapa_Acercar = document.querySelector("#Mapa-Acercar");
            if (Mapa_Acercar) {
                Mapa_Acercar.addEventListener("click", (e) => {
                    e.preventDefault();
                    Mapa.Zoom++;
                    Mapa.Map.setZoom(Mapa.Zoom);
                });
            }

            let Mapa_Inicial = document.querySelector("#Mapa-Inicial");
            if (Mapa_Inicial) {
                Mapa_Inicial.addEventListener("click", (e) => {
                    e.preventDefault();                        
                    Mapa.Map.panTo(new L.LatLng(Mapa.Center[0], Mapa.Center[1]));
                    Mapa.Map.setZoom(Mapa.ZoomInt);
                    Mapa.Zoom = Mapa.Map._zoom;
                });
            }

            let Mapa_Alejar = document.querySelector("#Mapa-Alejar");
            if (Mapa_Alejar) {
                Mapa_Alejar.addEventListener("click", (e) => {
                    e.preventDefault();
                    Mapa.Zoom--;
                    Mapa.Map.setZoom(Mapa.Zoom);
                });
            }

            let Mapa_Imprimir_Procesar = document.querySelector("#Mapa-Imprimir-Procesar");
            if (Mapa_Imprimir_Procesar) {
                Mapa_Imprimir_Procesar.addEventListener("click", (e) => {
                    e.preventDefault();

                    let Mapa_Imprimir_Nombre = document.querySelector("#Mapa-Imprimir-Nombre").value;
                    if (Mapa_Imprimir_Nombre.trim() == "") {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ingrese el nombre del mapa';
                        Mapa.Mensaje.show();
                        return;
                    }

                    var params = {};
                    params.comment = "...";
                    params.dpi = 150;
                    params.layout = "MC" + document.querySelector("#Mapa-Imprimir-Formato").value + (document.querySelector("[name='Mapa-Imprimir-Mapa']:checked").value == "Base" ? "" : "B");
                    params.nombre = document.querySelector("#Mapa-Imprimir-Nombre").value;
                    params.descripcion = document.querySelector("#Mapa-Imprimir-Descripcion").value;
                    params.nota = document.querySelector("#Mapa-Imprimir-Nota").value;
                    params.fuente = "Ministerio de Cultura";
                    params.layersMerging = false;
                    params.geodetic = true;
                    params.outputFormat = "pdf";

                    params.srs = Mapa.Map.options.crs.code;
                    //params.srs = "EPSG:4326";

                    let Scala = parseInt(Mapa.Level.find(resp => resp.level == Mapa.Map.getZoom()).scale);
                    if (document.querySelector("[name='Print-TipoEscala']:checked").value == "Select") {
                        Scala = parseInt(document.querySelector("#Mapa-Imprimir-Escala").value);
                    }
                    params.escala = new Intl.NumberFormat("es-PE").format(Scala);
                    let Mercator = Mapa.ConvertCoordinates(Mapa.Map.getCenter().lng, Mapa.Map.getCenter().lat);
                    params.pages = [];
                    params.pages.push({
                        center: [Mercator[0], Mercator[1]],
                        rotation: 0,
                        scale: Scala
                    });

                    // params.pages.push({
                    //     center: [Mapa.Map.getCenter().lng, Mapa.Map.getCenter().lat],
                    //     rotation: 0,
                    //     scale: Scala
                    // });

                    let wms = [];
                    let legends = [];

                    if (document.querySelector("[name='Mapa-Imprimir-Mapa']:checked").value == "Base") {
                        wms.push({
                            type: 'xyz',
                            baseURL: 'https://services.arcgisonline.com/arcgis/rest/services/World_Imagery/MapServer/tile/',
                            extension: 'png',
                            maxExtent: [-20037508.34, -20037508.34, 20037508.34, 20037508.34],
                            tileSize: [256, 256],
                            resolutions: [156543.03392800014, 78271.51696399994, 39135.75848200009, 19567.87924099992, 9783.93962049996, 4891.96981024998, 2445.98490512499, 1222.992452562495, 611.4962262813797, 305.74811314055756, 152.87405657041106, 76.43702828507324, 38.21851414253662, 19.10925707126831, 9.554628535634155, 4.77731426794937, 2.388657133974685, 1.1943285668550503, 0.5971642835598172, 0.29858214164761665],
                            tileOriginCorner: 'bl',
                            path_format: '${z}/${y}/${x}'
                        });
                    }
                    let LayerOrder = [];
                    Array.from(document.querySelectorAll(".clsLayers:checked[data-type='wms']")).forEach((item, i) => {
                        let tema = item.dataset.tema;
                        let grupo = item.dataset.grupo;
                        let layer = item.dataset.layer;
                        let itemLayer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == layer);
                        LayerOrder.push({
                            type : "wms",
                            tema: tema,
                            grupo: grupo,
                            layer: layer,
                            orden : itemLayer.orden,
                            nombre : itemLayer.nombre                           
                        });
                    });


                    Array.from(document.querySelectorAll(".clsLayers:checked[data-type='cluster']")).forEach((item, i) => {
                        let tema = item.dataset.tema;
                        let grupo = item.dataset.grupo;
                        let layer = item.dataset.layer;
                        let itemLayer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == layer);
                        LayerOrder.push({
                            type : "cluster",
                            tema: tema,
                            grupo: grupo,
                            layer: layer,
                            orden : itemLayer.orden,
                            nombre : itemLayer.nombre                           
                        });
                    });

                  
                    let LayersOrdenadas = alasql("select * from ? order by orden", [LayerOrder]);
                    LayersOrdenadas.forEach((item, i) => {
                        let ide_layer = "layer_" + item.tema + "_" + item.grupo + "_" + item.layer;
                        let LayerActive = Mapa.getLayer(ide_layer).find(resp => 0 == 0);
                        
                        let lyr_data = Mapa.Layers.Temas.find(resp => resp.ide == item.tema).Grupos.find(resp => resp.ide == item.grupo).Layers.find(resp => resp.ide == item.layer);
                        
                        if (item.type == "cluster"){     
                          
                            let Style = {};
                            LayerActive.getLayers().forEach((itemLayer, iLayer) => {
                                Style[iLayer] = {
                                    "externalGraphic": itemLayer.options.icon.options.iconUrl,
                                    "graphicWidth": lyr_data.icon.size[0],
                                    "graphicHeight": lyr_data.icon.size[1],
                                    "graphicXOffset": -5,
                                    "graphicYOffset": -17
                                }
                            })   

                            wms.push({
                                type:"Vector",                                    
                                styles: Style,            
                                styleProperty: "_mc_style",                        
                                geoJson: {type: "FeatureCollection",  features : LayerActive.getLayers().map(resp => [Mapa.ConvertCoordinates(resp._latlng.lng, resp._latlng.lat)[0], Mapa.ConvertCoordinates(resp._latlng.lng, resp._latlng.lat)[1]]).map((resp, inx) => ({ type: "Feature", "properties": { _mc_style : inx}, id : "OpenLayers.Feature.Vector_" + inx, geometry :  { type: "Point",  coordinates : resp }} ))},
                                name: item.nombre,
                                opacity: 1                                    
                            });
                              
                           
                            if (lyr_data.isLeyenda){
                                let classes = [];
                                if (lyr_data.icon.type == "key"){                                    
                                    if (LayerActive.getLayers().length > 10){
                                        LayerActive = LayerActive.getLayers().filter((resp, inx) => inx > 0 && inx <= 10 );

                                        LayerActive.forEach((itemLeyenda, iLeyenda) => {
                                            classes.push({
                                                iconBeforeName: true,
                                                icons: [itemLeyenda.options.icon.options.iconUrl],
                                                name: itemLeyenda.label
                                            })
                                        });                                    
                                    }else{
                                        LayerActive.getLayers().forEach((itemLeyenda, iLeyenda) => {
                                            classes.push({
                                                iconBeforeName: true,
                                                icons: [itemLeyenda.options.icon.options.iconUrl],
                                                name: itemLeyenda.label
                                            })
                                        });                                    
                                    } 
                                }

                                if (lyr_data.icon.type == "range"){        
                                    lyr_data.leyenda.imagenes.forEach((itemLeyenda, iLeyenda) => {
                                        classes.push({
                                            iconBeforeName: true,
                                            icons: [Mapa.BasePath + itemLeyenda.url],
                                            name: itemLeyenda.titulo
                                        })
                                    });
                                }

                                if (lyr_data.icon.type == "icon"){        
                                    lyr_data.leyenda.imagenes.forEach((itemLeyenda, iLeyenda) => {
                                        classes.push({
                                            iconBeforeName: true,
                                            icons: [Mapa.BasePath + itemLeyenda.url],
                                            name: itemLeyenda.titulo
                                        })
                                    });
                                }

                                if (lyr_data.icon.type == "categoria"){        
                                    lyr_data.leyenda.imagenes.forEach((itemLeyenda, iLeyenda) => {
                                        classes.push({
                                            iconBeforeName: true,
                                            icons: [Mapa.BasePath + itemLeyenda.url],
                                            name: itemLeyenda.titulo
                                        })
                                    });
                                }

                                legends.push({
                                    name: item.nombre,
                                    classes: classes
                                });                            
                            }
                            
                        }

                        if (item.type == "wms"){
                             wms.push({
                                baseURL: LayerActive._url,
                                format: LayerActive.options.format,
                                opacity: LayerActive.options.opacity,
                                singleTile: false,
                                styles: [""],
                                type: "WMS",
                                customParams: {
                                    TILED: true,
                                    TRANSPARENT: true
                                },
                                layers: LayerActive.options.layers,
                            });
    
                            if (lyr_data.isLeyenda){
                                let classes = [];

                                lyr_data.leyenda.imagenes.forEach((itemLeyenda, iLeyenda) => {
                                    classes.push({
                                        iconBeforeName: true,
                                        icons: [Mapa.BasePath + itemLeyenda.url],
                                        name: itemLeyenda.titulo
                                    })
                                });

                                legends.push({
                                    name: item.nombre,
                                    classes: classes
                                });  

                            }                               
                        }
                       
                    });

                    params.layers = wms;
                    params.legends = legends;

                    params.units = "m";
                    params.outputFilename = "Map-MC";
                   

                /*    let endpoint = Mapa.BasePath + "mapa/imprimir";
let data = {
    params: JSON.stringify(params),
    url: Mapa.ServerPrint
}

let getParams = Mapa.getFormData(data);

document.querySelector(".preloader").style.display = "block";

fetch(endpoint, {
    method: "POST",
    body: getParams,
    credentials: "same-origin"
})
.then((response) => {
    if (!response.ok) throw new Error("Error al generar el mapa");
    return response.blob(); // <-- recibimos el PDF como blob
})
.then((blob) => {
    document.querySelector(".preloader").style.display = "none";

    let nameInput = document.getElementById("Mapa-Imprimir-Nombre").value;
    let name = nameInput ? nameInput.trim() : new Date().getTime();
    name += ".pdf";

    let url = window.URL.createObjectURL(blob);

    let link = document.createElement("a");
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();

    window.URL.revokeObjectURL(url);
})
.catch((error) => {
    document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
    document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML =
        '<i class="fas fa-warning colorWarning"></i> Ocurrio un problema al generar el mapa';
    Mapa.Mensaje.show();

    document.querySelector(".preloader").style.display = "none";
    console.error('load', error);
});*/

                                    
                    
                   let endpoint = Mapa.BasePath + "mapa/imprimir";
                    let data = {
                        params : JSON.stringify(params),
                        url: Mapa.ServerPrint
                    }
                     
                    let getParams = Mapa.getFormData(data);

                    document.querySelector(".preloader").style.display = "block";
                    fetch(endpoint, {
                        method: "POST",
                        body: getParams,
                    })
                    .then((response) => {
                        return response.json();
                    })
                    .then((response) => {
                       
                        document.querySelector(".preloader").style.display = "none";
                        if (response) {
                            let name = new Date().getTime();
                            var link = document.createElement("a");
                            link.download = name;
                            link.href = response.getURL;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        }
                    }).catch((error) => {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ocurrio un problema al generar el mapa';
                        Mapa.Mensaje.show();

                        document.querySelector(".preloader").style.display = "none";
                        console.error('load', error);
                    });

                });
            }

            let Mapa_Contacto_Procesar = document.querySelector("#Mapa-Contacto-Procesar");
            if (Mapa_Contacto_Procesar) {
                Mapa_Contacto_Procesar.addEventListener("click", (e) => {
                    e.preventDefault();

                    let nombre = document.querySelector("#Mapa-Contacto-Nombre").value;
                    if (nombre.trim() == "") {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ingrese su nombre';
                        Mapa.Mensaje.show();
                        return;
                    }

                    let asunto = document.querySelector("#Mapa-Contacto-Asunto").value;
                    if (asunto.trim() == "") {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ingrese el asunto';
                        Mapa.Mensaje.show();
                        return;
                    }

                    let correo = document.querySelector("#Mapa-Contacto-Correo").value;
                    if (!Mapa.isEmail(correo)) {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> El correo electrónico no es valido';
                        Mapa.Mensaje.show();
                        return;
                    }
                    
                    var response = grecaptcha.getResponse();
                    if(response.length == 0){
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Valide que no es un robot';
                        Mapa.Mensaje.show();
                        return;
                    }
                     
                    let params = {
                        nombre: document.querySelector("#Mapa-Contacto-Nombre").value,
                        email: document.querySelector("#Mapa-Contacto-Correo").value,
                        asunto: document.querySelector("#Mapa-Contacto-Asunto").value,
                        mensaje: document.querySelector("#Mapa-Contacto-Mensaje").value
                    }

                    let endpoint = Mapa.BasePath + Mapa.Contacto;
                    let getParams = Mapa.getFormData(params);
                    document.querySelector(".preloader").style.display = "block";
                    fetch(endpoint, {
                            method: "POST",
                            body: getParams,
                        })
                        .then((response) => {
                            return response.json();
                        })
                        .then((response) => {
                            document.querySelector(".preloader").style.display = "none";

                            if (response.msg == 1) {
                                document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Bien";
                                document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = "Gracias por contactarnos";
                                Mapa.Mensaje.show();

                                $('#Mapa-Contacto').collapse('hide');

                            } else {
                                document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upsss";
                                document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ocurrio un problema al enviar los datos';
                                Mapa.Mensaje.show();
                            }

                        }).catch((error) => {
                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upsss";
                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ocurrio un problema al enviar los datos';
                            Mapa.Mensaje.show();

                            document.querySelector(".preloader").style.display = "none";
                            console.error('load', error);
                        });

                });
            }

            let Mapa_Shape_Ancho = document.querySelector("#Mapa-Shape-Ancho");
            if (Mapa_Shape_Ancho) {
                Mapa_Shape_Ancho.addEventListener("input", (e) => {
                    document.querySelector("#Mapa-Shape-Ancho-Label").innerHTML = e.target.value;
                })
            }

            let Mapa_Shape_Opacidad = document.querySelector("#Mapa-Shape-Opacidad");
            if (Mapa_Shape_Opacidad) {
                Mapa_Shape_Opacidad.addEventListener("input", (e) => {
                    document.querySelector("#Mapa-Shape-Opacidad-Label").innerHTML = e.target.value;
                })
            }

            let Mapa_Coordenadas_Procesar = document.querySelector("#Mapa-Coordenadas-Procesar");
            if (Mapa_Coordenadas_Procesar) {
                Mapa_Coordenadas_Procesar.addEventListener("click", (e) => {
                    e.preventDefault();

                    let sistema = document.querySelector("#Mapa-Coordenadas-Sistema").value;
                    let descripcion = document.querySelector("#Mapa-Coordenadas-Descripcion").value;
                    let norte = document.querySelector("#Mapa-Coordenadas-Norte").value;
                    let este = document.querySelector("#Mapa-Coordenadas-Este").value;

                    if (!Mapa.isNumber(norte)) {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ingrese un valor valido para la coordenada norte';
                        Mapa.Mensaje.show();
                        return;
                    }

                    if (!Mapa.isNumber(este)) {
                        document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                        document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> Ingrese un valor valido para la coordenada este';
                        Mapa.Mensaje.show();
                        return;
                    }

                    let layerTemp = L.layerGroup();
                    var bounds = L.latLngBounds()

                    if (sistema == "4326") {
                        layerTemp.addLayer(L.marker([este, norte]).bindPopup('<b>' + descripcion + "</b>"));
                        let lat_lng = [este, norte];
                        bounds.extend(lat_lng);
                    } else {
                        let Zona = 0;
                        if (sistema == "32717")
                            Zona = 17;
                        if (sistema == "32718")
                            Zona = 18;
                        if (sistema == "32719")
                            Zona = 19;

                        var easting = parseFloat(este);
                        var northing = parseFloat(norte);
                        var southern = 'S';
                        var ellipsoid = "WGS84";
                        var utmProj = "+proj=utm +zone=" + Zona + " +units=m +ellps=" + ellipsoid + (southern ? " +south +no_defs" : " +no_defs");
                        var latlngProj = getLatLngProjString(ellipsoid);
                        var coords = doConversion(utmProj, latlngProj, easting, northing, Zona, southern)
                        let marker = L.marker([coords.latlng.lat, coords.latlng.lng]).bindPopup('<b>' + descripcion + "</b>");
                        layerTemp.addLayer(marker);

                        let lat_lng = [coords.latlng.lat, coords.latlng.lng];
                        bounds.extend(lat_lng);


                    }
                    layerTemp.id = "LayerPuntos";
                    Mapa.Map.addLayer(layerTemp);
                    Mapa.Map.setView(bounds.getCenter(), Mapa.MaxZoomPoint);

                    document.querySelector("#Mapa-Coordenadas-Descripcion").value = "";
                    document.querySelector("#Mapa-Coordenadas-Norte").value = "";
                    document.querySelector("#Mapa-Coordenadas-Este").value = "";

                    Mapa.Zoom = Mapa.Map._zoom;
                })
            }

            let Mapa_Coordenadas_Limpiar = document.querySelector("#Mapa-Coordenadas-Limpiar");
            if (Mapa_Coordenadas_Limpiar) {
                Mapa_Coordenadas_Limpiar.addEventListener("click", (e) => {
                    e.preventDefault();
                    let layers = Mapa.getLayer("LayerPuntos");
                    layers.forEach(item => {
                        Mapa.Map.removeLayer(item);
                    });

                });
            }

            let Mapa_Medir = document.querySelector("#Mapa-Medir");
            if (Mapa_Medir) {
                Mapa_Medir.addEventListener("click", (e) => {
                    e.preventDefault();

                    let activo = e.target.dataset.activo;
                    if (activo == 0) {
                        e.target.dataset.activo = 1;
                        Mapa.Medir._startMeasuring();
                    } else {
                        Mapa.Map._container.style.cursor = '';
                        Mapa.Medir._stopMeasuring();
                        e.target.dataset.activo = 0;
                    }
                });
            }

            let Mapa_Imprimir_TipoEscala_Mapa = document.querySelector("#Mapa-Imprimir-TipoEscala-Mapa");
            if (Mapa_Imprimir_TipoEscala_Mapa) {
                Mapa_Imprimir_TipoEscala_Mapa.addEventListener("click", (e) => {
                    document.querySelector("#Mapa-Imprimir-Escala").disabled = true;
                });
            }

            let Mapa_Imprimir_TipoEscala_Select = document.querySelector("#Mapa-Imprimir-TipoEscala-Select");
            if (Mapa_Imprimir_TipoEscala_Select) {
                Mapa_Imprimir_TipoEscala_Select.addEventListener("click", (e) => {
                    document.querySelector("#Mapa-Imprimir-Escala").disabled = false;
                });
            }

            //Limites
            //Limites Departamento
            let Mapa_Filter_Departamento = document.querySelector("#Mapa-Filter-Departamento");
            if (Mapa_Filter_Departamento) {
                Mapa_Filter_Departamento.addEventListener("change", (e) => {
                    let value = e.target.value;
                    let Mapa_Filter_Provincia = document.querySelector("#Mapa-Filter-Provincia");
                    if (Mapa_Filter_Provincia) {
                        let Provincias = alasql("SELECT idprov, nombprov from ? WHERE iddpto = '" + value + "' GROUP BY idprov, nombprov order by nombprov ", [Mapa.Limites.Distritos]);

                        document.querySelectorAll('#Mapa-Filter-Provincia  option').forEach(option => option.remove());
                        Mapa_Filter_Provincia.add(new Option("-- Seleccione --", "0", false, false));
                        Provincias.forEach((itemProvincia, iProvincia) => {
                            if (itemProvincia.idprov) {
                                var data = new Option(itemProvincia.nombprov, itemProvincia.idprov, false, false);
                                Mapa_Filter_Provincia.add(data);
                            }
                        });

                        let existeLayer = Mapa.getLayer(Mapa.idDepartamento);
                        if (existeLayer) {
                            if (existeLayer.length) {
                                if (parseInt(value) > 0) {
                                    let geom = Mapa.Limites.Departamentos.find(resp => resp.iddpto == value);
                                    if (geom) {
                                        var coords = geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                        var envelope = L.polygon(coords);
                                        Mapa.Map.fitBounds(envelope.getBounds(), true);
                                        geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                    }
                                } else {
                                    document.querySelector("#Mapa-Inicial").click();
                                }
                            }
                        }
                        Mapa_Filter_Provincia.dispatchEvent(new Event("change"));
                    }
                });
            }

            //Limites Provincia
            let Mapa_Filter_Provincia = document.querySelector("#Mapa-Filter-Provincia");
            if (Mapa_Filter_Provincia) {
                Mapa_Filter_Provincia.addEventListener("change", (e) => {
                    let value = e.target.value;
                    let Mapa_Filter_Distrito = document.querySelector("#Mapa-Filter-Distrito");
                    if (Mapa_Filter_Distrito) {
                        let Distritos = alasql("SELECT iddist, nombdist from ? WHERE idprov = '" + value + "' ORDER BY iddist ", [Mapa.Limites.Distritos]);

                        document.querySelectorAll('#Mapa-Filter-Distrito  option').forEach(option => option.remove());
                        Mapa_Filter_Distrito.add(new Option("-- Seleccione --", "0", false, false));
                        Distritos.forEach((itemDistrito, iDistrito) => {
                            var data = new Option(itemDistrito.nombdist, itemDistrito.iddist, false, false);
                            Mapa_Filter_Distrito.add(data);
                        });

                        let existeLayer = Mapa.getLayer(Mapa.idProvincia);
                        if (existeLayer) {
                            if (existeLayer.length) {
                                if (parseInt(value) > 0) {
                                    let geom = Mapa.Limites.Provincias.find(resp => resp.idprov == value);
                                    if (geom) {
                                        var coords = geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                        var envelope = L.polygon(coords);
                                        Mapa.Map.fitBounds(envelope.getBounds());
                                        geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                    }
                                } else {
                                    existeLayer = Mapa.getLayer(Mapa.idDepartamento);
                                    if (existeLayer) {
                                        if (existeLayer.length) {
                                            let geom = Mapa.Limites.Departamentos.find(resp => resp.iddpto == document.querySelector("#Mapa-Filter-Departamento").value);
                                            if (geom) {
                                                var coords = geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                                var envelope = L.polygon(coords);
                                                Mapa.Map.fitBounds(envelope.getBounds());
                                                geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                            }

                                        }
                                    }
                                }
                            }
                        }
                        Mapa_Filter_Distrito.dispatchEvent(new Event("change"));
                    }
                });
            }

            //Limites Distrito
            let Mapa_Filter_Distrito = document.querySelector("#Mapa-Filter-Distrito");
            if (Mapa_Filter_Distrito) {
                Mapa_Filter_Distrito.addEventListener("change", (e) => {
                    let value = e.target.value;
                    let existeLayer = Mapa.getLayer(Mapa.idDistrito);
                    if (existeLayer) {
                        if (existeLayer.length) {
                            if (parseInt(value) > 0) {
                                let geom = Mapa.Limites.Distritos.find(resp => resp.iddist == value);
                                if (geom) {
                                    var coords = geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                    var envelope = L.polygon(coords);
                                    Mapa.Map.fitBounds(envelope.getBounds());
                                    geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                }
                            } else {
                                existeLayer = Mapa.getLayer(Mapa.idProvincia);
                                if (existeLayer) {
                                    if (existeLayer.length) {
                                        let geom = Mapa.Limites.Provincias.find(resp => resp.idprov == document.querySelector("#Mapa-Filter-Provincia").value);
                                        if (geom) {
                                            var coords = geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                            var envelope = L.polygon(coords);
                                            Mapa.Map.fitBounds(envelope.getBounds());
                                            geom.envelope.coordinates[0].map(resp => (resp.reverse()));
                                        }
                                    }
                                }
                            }
                        }
                    }

                    document.querySelectorAll('.clsLayerFiltro[data-fixed="0"] option').forEach(option => option.remove());
//                    document.querySelectorAll('.clsLayerFiltro[data-fixed="0"]').forEach(select => select.add(new Option("Procesando...", "", false, false)));                    
                    
                    //Movemos estadisticos
                    document.querySelectorAll(".clsBotones").forEach((item, i) => {
                        let lyr = document.querySelector("#layer_" + item.dataset.tema + "_" + item.dataset.grupo + "_" + item.dataset.layer);
                        if (lyr){
                            if (lyr.checked){
                                try{
                                    setTimeout(() => {
                                        if (document.getElementById('Mapa-Contenido').classList.contains("show")){
                                            document.querySelector("#LayerBoton-" + item.dataset.tema + "_" + item.dataset.grupo + "_" + item.dataset.layer + "_0").click();        
                                        }
                                    }, 500);                                        
                                }catch(ex){
                                    console.log("Error", ex);
                                }
                                
                            }
                        }
                    });

                    document.querySelectorAll(".clsLayers:checked").forEach((item, i) => {
                        let tema = item.dataset.tema;
                        let grupo = item.dataset.grupo;
                        let idLayer = item.dataset.layer;
                        let layer = Mapa.Layers.Temas.find(resp => resp.ide == tema).Grupos.find(resp => resp.ide == grupo).Layers.find(resp => resp.ide == idLayer);
                        let ide_layer = "layer_" + tema + "_" + grupo + "_" + idLayer;
                        let existeLayer = Mapa.getLayer(ide_layer);
                        
                        if (layer.isExtend) {
                            if (layer.Extend) {
                                if (layer.Extend.Filtros) {
                                    if (layer.Extend.Filtros.length == 0) {
                                        existeLayer.forEach((itemLayer, i) => {
                                            Mapa.Map.removeLayer(itemLayer);
                                        });
                                        Mapa.addLayer(tema, grupo, layer);
                                    } else {
                                        let params = {};
                                        let inxFiltro = 0;
                                        let Filter = layer.Extend.Filtros[0];
                                        if (layer.Extend.type == "fetch") {
                                            for (var item in Filter.params) {
                                                if (Filter.params[item] == "LayerFiltro") {
                                                    if (document.querySelector("#" + Filter.params[item] + "-" + tema + "_" + grupo + "_" + layer.ide + "_" + inxFiltro)) {
                                                        params[item] = document.querySelector("#" + Filter.params[item] + "-" + tema + "_" + grupo + "_" + layer.ide + "_" + inxFiltro).value;
                                                        inxFiltro++;
                                                    }
                                                } else {
                                                    try {
                                                        if (document.querySelector("#" + Filter.params[item])) {
                                                            params[item] = document.querySelector("#" + Filter.params[item]).value;
                                                        } else {
                                                            params[item] = Filter.params[item];
                                                        }

                                                    } catch (ex) {
                                                        params[item] = Filter.params[item];
                                                    }
                                                }
                                            }

                                            let endpoint = Mapa.BasePath + Filter.url;
                                            let getParams = Mapa.getFormData(params);

                                            if (Filter.isFixed) {
                                                let cboNextFilter = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_0");
                                                if (cboNextFilter)
                                                    cboNextFilter.dispatchEvent(new Event("change"));
                                            } else {
                                                fetch(endpoint, {
                                                        method: "POST",
                                                        body: getParams,
                                                    })
                                                    .then((response) => {
                                                        return response.text();
                                                    })
                                                    .then((response) => {
                                                        if (response) {
                                                            let obj = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_0");
                                                            if (obj) {
                                                                obj.innerHTML = response;
                                                                if (layer.Extend.Filtros.length > 0) {
                                                                    let cboNextFilter = document.querySelector("#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_0");
                                                                    if (cboNextFilter)
                                                                        cboNextFilter.dispatchEvent(new Event("change"));
                                                                }
                                                            }
                                                        }else{
                                                            


                                                            document.querySelector("#Mapa-Mensaje-Titulo").innerHTML = "Upss";
                                                            document.querySelector("#Mapa-Mensaje-Cuerpo").innerHTML = '<i class="fas fa-warning colorWarning"></i> No se encontraron datos de ' + layer.nombre + ' para la selección actual';
                                                            Mapa.Mensaje.show();
                                                            return;
                                                        }
                                                    }).catch((error) => {
                                                        console.error('load', error);
                                                    });
                                            }

                                        } else {
                                            existeLayer.forEach((itemLayer, i) => {
                                                Mapa.Map.removeLayer(itemLayer);
                                            });
                                            Mapa.addLayer(tema, grupo, layer)
                                        }
                                    }

                                } else {
                                    existeLayer.forEach((itemLayer, i) => {
                                        Mapa.Map.removeLayer(itemLayer);
                                    });
                                    Mapa.addLayer(tema, grupo, layer)
                                }
                            } else {
                                existeLayer.forEach((itemLayer, i) => {
                                    Mapa.Map.removeLayer(itemLayer);
                                });
                                Mapa.addLayer(tema, grupo, layer)
                            }
                        } else {
                            existeLayer.forEach((itemLayer, i) => {
                                Mapa.Map.removeLayer(itemLayer);
                            });
                            Mapa.addLayer(tema, grupo, layer)
                        }

                    });

                });
            }


        },

      
        SelectMarker: (e) => {
            let layer = e.target.localLayer;
            let label = e.target.label;
            let lat = e.latlng.lat;
            let lng = e.latlng.lng;
            let tema = e.target.tema;
            let grupo = e.target.grupo;
            let idLayer = e.target.localLayer.ide;
            let icon = e.target.icon;            

            if (layer.isInfo) {
                if (layer.info) {
                    if (layer.info.isZoom) {                      
                        Mapa.Map.setView([lat, lng], layer.info.zoom);                
                    }

                    let params = {};
                    params["capa"] = layer.info.capa;
                    params[layer.info.key] = e.target.ide;

                    let getParams = Mapa.getFormData(params);
                    let endpoint = Mapa.BasePath + layer.info.url;
                    document.querySelector(".preloader").style.display = "block";
                    fetch(endpoint, {
                            method: "POST",
                            body: getParams,
                        })
                        .then((response) => {
                            return response.text();
                        })
                        .then((response) => {
                            document.querySelector(".preloader").style.display = "none";
                            if (response) {
                                Mapa.BSOffcanvas.show();
                                document.querySelector("#Mapa-Contenido-Label").innerHTML = layer.nombre;                                
                                $("#Mapa-Contenido-Contenedor").html($(response));

                                if (Mapa.MarkerSelected) {
                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                }

                                let iconFeature = Mapa.IconFeature;
                                if (layer.info.iconSelected){
                                        if (layer.info.iconCategoria){
                                            iconFeature = L.icon({
                                                iconUrl: Mapa.BasePath + layer.info.iconSelected + icon.split('/').pop().split('.')[0] + layer.info.iconCategoriaSelect, 
                                                iconSize: layer.icon.size,
                                                shadowSize: [60, 60],
                                                iconAnchor: [40, 40],
                                                shadowAnchor: [4, 4],
                                                popupAnchor: [-25, -40]
                                            });
                                        }else{
                                            iconFeature = L.icon({
                                                iconUrl: Mapa.BasePath + layer.info.iconSelected,
                                                iconSize: layer.icon.size,
                                                shadowSize: [60, 60],
                                                iconAnchor: [40, 40],
                                                shadowAnchor: [4, 4],
                                                popupAnchor: [-25, -40]
                                            });
                                        }

                                        Mapa.MarkerSelected = L.marker([lat, lng], { icon: iconFeature }).bindTooltip(label, {
                                            direction: 'center',
                                            permanent: false,
                                            noWrap: true,
                                            opacity: 0.75
                                        });
                                        Mapa.Map.addLayer(Mapa.MarkerSelected);
                                    
                                }  
                                if (layer.isExtend){
                                    if (layer.Extend){
                                        if (layer.Extend.isSelected){
                                            let ide_filtro = "#LayerFiltro-" + tema + "_" + grupo + "_" + idLayer + "_" + layer.Extend.isSelected;
                                            let select = document.querySelector(ide_filtro);
                                            if (select){
                                                select.value = e.target.ide;
                                                select.dispatchEvent(new Event("change"));
                                            }
                                            
                                        }
                                    }
                                }
    

                            } else {

                                if (Mapa.MarkerSelected) {
                                    Mapa.Map.removeLayer(Mapa.MarkerSelected);
                                }
                                Mapa.BSOffcanvas.hide();
                            }
                        }).catch((error) => {
                            Mapa.BSOffcanvas.hide();

                            document.querySelector(".preloader").style.display = "none";
                            console.error('load', error);
                        });

                }
            }
        },

        LoadSHP: () => {
            var file = document.querySelector("#Mapa-Shape-File");
            var reader = new FileReader();
            reader.onload = function() {
                if (reader.readyState != 2 || reader.error) {
                    console.error(reader);
                    return;
                } else {

                    shp(reader.result).then(function(geojson) {
                        let layerTemp = L.geoJSON(geojson, {
                            onEachFeature: function(feature, layer) {
                                if (feature.properties) {
                                    layer.bindPopup(Object.keys(feature.properties).map(function(k) {
                                        return k + ": " + feature.properties[k];
                                    }).join("<br />"), {
                                        maxHeight: 200
                                    });
                                }
                            }
                        });
                        layerTemp.id = "LayerSHP";
                        layerTemp.setStyle(Mapa.getStyle());
                        Mapa.Map.addLayer(layerTemp);
                        let box = layerTemp.getBounds();
                        Mapa.Map.fitBounds(box);

                        Mapa.Zoom = Mapa.Map._zoom;
                    });
                }
            }
            reader.readAsArrayBuffer(file.files[0])
        },

        getLayer: function(ide) {

            var myLayer = [];
            Mapa.Map.eachLayer(function(layer) {
                if (layer.id == ide) {
                    myLayer.push(layer);
                }
            })
            return myLayer;

        },

        ConvertCoordinates: (lon, lat) => {
            var x = (lon * 20037508.34) / 180;
            var y = Math.log(Math.tan(((90 + lat) * Math.PI) / 360)) / (Math.PI / 180);
            y = (y * 20037508.34) / 180;
            return [x, y];
        },

        getStyle: () => {
            return {
                fillColor: document.querySelector("#Mapa-Shape-Fondo").value,
                weight: document.querySelector("#Mapa-Shape-Ancho").value,
                fillOpacity: (document.querySelector("#Mapa-Shape-Opacidad").value / 100),
                color: document.querySelector("#Mapa-Shape-Borde").value,

            };
        },

        isEmail: (txt) => {
            return txt.match(
                /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/
            );;
        },

        isNumber: (txt) => {
            return !isNaN(parseFloat(txt)) && isFinite(txt);
        },

        getGeoJSON :(layer) => {
            var lng, lat, coords = [];
        
            if (layer instanceof L.Polygon) {
                var latlngs = layer.getLatLngs();
                for (var i = 0; i < latlngs[0].length; i++) {
                    coords.push(latlngs[0][i].lng + " " + latlngs[0][i].lat);
                    if (i === 0) {
                        lng = latlngs[0][i].lng;
                        lat = latlngs[0][i].lat;
                    }
                }
        
                if (latlngs[0].length > 0) {
                    var polygon = L.polygon(latlngs, { color: 'red' });
                    var json = polygon.toGeoJSON();
                    L.extend(json.properties, {
                        comentario: layer._popup._content
                    })
                    return json;
                } else {
                    return null;
                }
                //  return "POLYGON((" + coords.join(",") + "," + lng + " " + lat + "))";
        
            } else if (layer instanceof L.Polyline) {
                var latlngs = layer.getLatLngs();
                for (var i = 0; i < latlngs.length; i++) {
                    latlngs[i]
                    coords.push(latlngs[i].lng + " " + latlngs[i].lat);
                    if (i === 0) {
                        lng = latlngs[i].lng;
                        lat = latlngs[i].lat;
                    }
                }
        
                if (latlngs.length > 0) {
                    var polyline = L.polyline(latlngs, { color: 'red' });
                    var json = polyline.toGeoJSON();
        
                    L.extend(json.properties, {
                        comentario: layer._popup._content
                    })
                    return json;
                } else {
                    return null;
                }
        
        
                // return "LINESTRING(" + coords.join(",") + ")";
        
            } else if (layer instanceof L.Marker) {
        
                var marker = L.marker(layer.getLatLng());
                var json = marker.toGeoJSON();
        
                L.extend(json.properties, {
                    comentario: (layer._popup ? layer._popup._content : layer.options.icon.options.html)
                })
                return json;
        
            }
        }

        
}            


