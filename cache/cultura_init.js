var base_url = "";

$(window).on('scroll').on('load', function() {
    preLoader();
});

// BEGIN: 01 Preloader
var preLoader = function() {
    if ($('.preloader').length) {
        var $preloader = $('.preloader');
        $preloader.delay(200).fadeOut(600);
    }
};

$(".draggable").draggableTouch();

$("#headerLegend").mousedown(function() {
    $(".draggable").draggableTouch();
});

$("#headerFilter").mousedown(function() {
    $(".draggable").draggableTouch();
});

$("#headerDownload").mousedown(function() {
    $(".draggable").draggableTouch();
});


$(".no-drag").mousedown(function() {
    $(".draggable").draggableTouch("disable");
});

// Inicializar  scrollbar
$(".js-scrollbar").mCustomScrollbar({
    setHeight: 600,
    theme: "minimal-dark"
});

// Inicializar  tooltip
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
    // Inicializar  Popover
var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
var popoverList = popoverTriggerList.map(function(popoverTriggerEl) {
    return new bootstrap.Popover(popoverTriggerEl)
})



// Expandir  mapas base//
$('.link-basemap .gh-thumbnail-map').click(function() {
    $('.link-basemap .gh-thumbnail-map').removeClass('active');
    $(this).addClass("active");

});


$('.btn-tool').click(function() {
    $('.btn-tool').removeClass('active');
    $(this).addClass("active");

});

// Expandir  Módulo
$('.gh-btn-max').on('click', function() {
    var btnmax = $(".gh-btn-max.active");
    if (btnmax.is(":visible")) {
        $(this).removeClass("active");

    } else {
        $(this).addClass("active")

    }
    functionmax();
});

function functionmax() {
    var maxToggler = $(".gh-toggled-max");
    if (maxToggler.is(":visible")) {
        $(".left-panel-section").removeClass("gh-toggled-max")
    } else {
        $(".left-panel-section").addClass("gh-toggled-max")

    }
}

//  Módulos, botones izquierdos, activar
$('.left-panel-section-open-label').on('click', function() {
    var activeclass = $("#" + event.target.id + ".active");
    if (activeclass.is(":visible")) {
        $(this).removeClass('active');
        $('.left-panel-section-open-label').removeClass('target-left');
        $(".left-panel-section").removeClass("show");
        $('.gh-return').removeClass('active');
        returnapp();
    } else {

        $('.left-panel-section-open-label').removeClass('active');
        $(this).addClass('active');
        $('.left-panel-section-open-label').addClass('target-left');
        $(".left-panel-section").removeClass("show");
        $("#" + event.target.id + "-content").addClass("show");
        $(".left-panel-section").removeClass("gh-toggled-max");
        $('.gh-return').removeClass('active');
        returnapp();
    }

});

//  Cerrar Modulos
$('.btn-close').on('click', function() {
    $('.left-panel-section-open-label').removeClass('active')
    $('.left-panel-section-open-label').removeClass('target-left');
    $(".left-panel-section").removeClass("show");
    $(".left-panel-section").removeClass("gh-toggled-max")
});


// activate
$('.btn-tool-creation  .item-tool').click(function() {
    $(this).addClass('active').siblings().removeClass('active');
});
$('.btn-tool-edition  .item-tool').click(function() {
    $(this).toggleClass('active');
});
// activate capas
$('.gh-btn-capa ').click(function() {

    var activeToggler = $(".gh-btn-capa.actives");
    if (activeToggler.is(":visible")) {
        $(".gh-btn-capa").removeClass("actives");
        $(".gh-capas").removeClass("show");
    } else {
        $(".gh-btn-capa").addClass("actives")
        $(".gh-capas").addClass("show")
    }
});


// submenu capas

$('.hovercapa').mouseover(function() {
    $('#ContentSubLayer').addClass('show')
});

$('.hovercapa').mouseout(function() {
    t = setTimeout(function() {
        $('#ContentSubLayer').removeClass('show')
    }, 100);

    $('#ContentSubLayer').on('mouseenter', function() {
        $('#ContentSubLayer').addClass('show')
        clearTimeout(t);
    }).on('mouseleave', function() {
        $('#ContentSubLayer').removeClass('show')
    })
});

$('.svg-icon').click(function() {
    var linkmapabase = $(".svg-icon.active");
    if (linkmapabase.is(":visible")) {
        $(".svg-icon").removeClass('active');
        $(this).addClass('active')

    } else {
        $(this).addClass('active')

    }

});


$('.list-gestion .list-group-item').click(function() {
    $(this).addClass('active').siblings().removeClass('active');
});

$('.list-group .open').click(function() {
    $(this).removeClass('open');
});


function changeURL(urlapp) {
    $('.left-panel-section').addClass('gh-toggled-max');
    $('#content-iframe').addClass('show');
    $('#list-app').addClass('d-none');
    $('.gh-return').addClass('active');

    var ifr = document.getElementById("idframe");
    ifr.setAttribute("src", urlapp);
}

function returnapp() {
    $('.left-panel-section').removeClass('gh-toggled-max');
    $('#content-iframe').removeClass('show');
    $('#list-app').removeClass('d-none');
}

$('.gh-return').click(function() {
    returnapp();
    $('.gh-return').removeClass('active');
});

function getLatLngProjString(ellipsoid) {
    var proj = "+proj=longlat +ellps=" + ellipsoid + " +units=m +no_defs";
    return proj;
}

function doConversion(from, to, x, y, zone, south) {
    var isWGS = from.includes("WGS84");
    var srcLL = from.includes("longlat");
    var proj = proj4(from, to);
    var point = proj4.toPoint([x, y]);
    var results = proj.forward(point);

    if (srcLL) {
        if (isWGS) {
            var natoMgrs = decodeMgrsString(proj4.mgrs.forward([x, y]));
            var rv = {
                global: {
                    easting: Math.round(results.x),
                    northing: Math.round(results.y),
                    zone: zone,
                    southern: south
                },
                nato: {
                    easting: natoMgrs.easting,
                    northing: natoMgrs.northing,
                    lngZone: natoMgrs.lngZone,
                    latZone: natoMgrs.latZone,
                    digraph: natoMgrs.digraph
                }
            };
            return rv;
        } else {
            var rv = {
                global: {
                    easting: Math.round(results.x),
                    northing: Math.round(results.y),
                    zone: zone,
                    southern: south
                },
                nato: {
                    easting: "",
                    northing: "",
                    lngZone: "",
                    latZone: "",
                    digraph: ""
                }
            };
            return rv;
        }
    } else {
        if (isWGS) {
            var natoMgrs = decodeMgrsString(proj4.mgrs.forward([results.x, results.y]));
            var rv = {
                latlng: {
                    lng: results.x,
                    lat: results.y
                },
                nato: {
                    easting: natoMgrs.easting,
                    northing: natoMgrs.northing,
                    lngZone: natoMgrs.lngZone,
                    latZone: natoMgrs.latZone,
                    digraph: natoMgrs.digraph
                }
            };
            return rv;
        } else {
            var rv = {
                latlng: {
                    lng: results.x,
                    lat: results.y
                },
                nato: {
                    easting: "",
                    northing: "",
                    lngZone: "",
                    latZone: "",
                    digraph: ""
                }
            };
            return rv;
        }
    }
}

function decodeMgrsString(mgrsString) {
    var sb = "";
    var i = 0;
    var length = mgrsString.length;

    while (!(/[A-Z]/).test(testChar = mgrsString.charAt(i))) {
        if (i >= 2) {
            return false;
            //throw ("MGRSPoint bad conversion from: " + mgrsString);
        }
        sb += testChar;
        i++;
    }
    var zoneNumber = parseInt(sb, 10);

    if (i === 0 || i + 3 > length) {
        // A good MGRS string has to be 4-5 digits long,
        // ##AAA/#AAA at least.
        return false;
        //throw ("MGRSPoint bad conversion from: " + mgrsString);
    }

    var zoneLetter = mgrsString.charAt(i++);

    // Should we check the zone letter here? Why not.
    if (zoneLetter <= 'A' || zoneLetter === 'B' || zoneLetter === 'Y' || zoneLetter >= 'Z' || zoneLetter === 'I' || zoneLetter === 'O') {
        return false;

        //throw ("MGRSPoint zone letter " + zoneLetter + " not handled: " + mgrsString);
    }

    var digraph = mgrsString.substring(i, i += 2);

    var remainder = length - i;
    if (remainder % 2 !== 0) {
        return false;
        //throw ("MGRSPoint has to have an even number \nof digits after the zone letter and two 100km letters - front \nhalf for easting meters, second half for \nnorthing meters" + mgrsString);
    }

    var sep = remainder / 2;

    var sepEasting = "0.0";
    var sepNorthing = "0.0";
    sepEasting = mgrsString.substring(i, i + sep);
    sepNorthing = mgrsString.substr(i + sep);

    return {
        lngZone: zoneNumber,
        latZone: zoneLetter,
        digraph: digraph,
        easting: sepEasting,
        northing: sepNorthing
    };
}


$('body').on('click','.btn_ver_virtual',function(){
    var ruta = $(this).attr('ruta_virtual');
    $.fancybox.open({
        src:ruta,
        type:'iframe',
        opts:{             
            fullScreen:true,
        }
    });
});

$("body").on("click",'.fancybox',function(e){
    e.preventDefault();
    var ruta = $(this).attr('href');
    $.fancybox.open({
        src:ruta,
        type:'iframe',
        opts:{
            iframe:{
                css:{
                    width:800,
                    height:550
                }
            },
            fullScreen:true,
        }
    });

});

