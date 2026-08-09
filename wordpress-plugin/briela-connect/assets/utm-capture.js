/**
 * Captura utm_source / utm_medium / utm_campaign de la URL al aterrizar y
 * los guarda en una cookie de primer toque: si ya hay una atribución
 * guardada y la página actual no trae parámetros utm_*, no se toca —
 * así una visita directa más tarde no borra por dónde entró la persona
 * realmente.
 */
(function () {
    if (typeof BrielaConnectUtm === 'undefined') return;

    var parametros = new URLSearchParams(window.location.search);
    var utmSource = parametros.get('utm_source');

    // Sin utm_source no hay campaña que atribuir: se deja la cookie como
    // estaba (o sin crear, si es la primera visita).
    if (!utmSource) return;

    var datos = {
        pagina_origen: window.location.href,
        utm_source: utmSource,
        utm_medium: parametros.get('utm_medium') || '',
        utm_campaign: parametros.get('utm_campaign') || '',
    };

    var dias = parseInt(BrielaConnectUtm.dias, 10) || 30;
    var fecha = new Date();
    fecha.setTime(fecha.getTime() + dias * 24 * 60 * 60 * 1000);

    document.cookie = BrielaConnectUtm.cookie + '=' + encodeURIComponent(JSON.stringify(datos))
        + '; expires=' + fecha.toUTCString() + '; path=/; SameSite=Lax';
})();
