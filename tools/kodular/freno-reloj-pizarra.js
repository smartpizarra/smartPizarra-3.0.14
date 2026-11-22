/* Versión del archivo: 1.0.0 (2026-11-22) */
/*
 * Smart Pizarra · Freno del reloj de la pizarra externa (SmartBet "PizarrasV2")
 *
 * PROBLEMA (medido en el TV box .137 el 2026-11-22):
 *   La página https://sport.grupoazar.do/PizarrasV2 reescribe el reloj #smbtClock
 *   10 veces por segundo (innerHTML), aunque solo muestra segundos. Cada reescritura
 *   obliga a redibujar la WebView (~75 ms por cuadro en el HiSilicon), así que la app
 *   y su WebView gastaban casi un núcleo entero en una tabla que casi no cambia.
 *     Sin freno: CPU del sistema 37 %, 205 cuadros en 20 s.
 *     Con freno: CPU del sistema  8 %,  22 cuadros en 20 s. El reloj sigue marcando los segundos.
 *
 * QUÉ HACE:
 *   Sustituye el setter de innerHTML SOLO del elemento #smbtClock: si el texto nuevo es
 *   igual al que ya hay, no lo escribe (no hay redibujado). Si cambia, lo escribe como
 *   siempre. No toca nada más de la página. Si #smbtClock no existe (otra página), no hace nada.
 *   Es idempotente: ejecutarlo dos veces en la misma página no tiene efecto extra.
 *
 * CÓMO USARLO EN KODULAR (bloques):
 *   La pizarra se recarga sola cada 120 s (envía su formulario), y en cada carga se pierde
 *   el freno. Por eso hay que ejecutarlo en CADA carga de página:
 *     cuando WebViewer1.PageLoaded  ->  llamar WebViewer1.RunJavaScript (o EvaluateJavaScript)
 *                                       con el texto de la línea de abajo (una sola línea).
 *
 * LÍNEA PARA PEGAR EN EL BLOQUE DE TEXTO:
 *   (function(){var e=document.getElementById('smbtClock');if(!e||e.__sp)return;e.__sp=1;var d=Object.getOwnPropertyDescriptor(Element.prototype,'innerHTML');Object.defineProperty(e,'innerHTML',{configurable:true,get:function(){return d.get.call(e)},set:function(v){if(d.get.call(e)!==v)d.set.call(e,v)}})})();
 *
 * Versión legible (equivalente) a continuación. ES5 para el WebView 101 del TV box.
 */
(function () {
  // Reloj de la pizarra; si no está, esta página no es la pizarra y no se hace nada
  var e = document.getElementById('smbtClock');
  // __sp marca que el freno ya está puesto en este elemento (evita ponerlo dos veces)
  if (!e || e.__sp) return;
  e.__sp = 1;
  // Getter/setter originales de innerHTML (los del prototipo de Element)
  var d = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
  // Propiedad propia del elemento: lee igual que siempre y solo escribe si el texto cambia
  Object.defineProperty(e, 'innerHTML', {
    configurable: true,
    get: function () { return d.get.call(e); },
    set: function (v) { if (d.get.call(e) !== v) d.set.call(e, v); }
  });
})();
