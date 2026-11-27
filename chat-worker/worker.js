/*
 * Umbral Analytics · Intermediario del chat con IA (Cloudflare Worker)
 *
 * La página manda: el código de acceso (ya cifrado), la conversación y los datos del cerebro.
 * Este Worker comprueba que el código siga en data/acceso.json y le pregunta a Gemini
 * usando la clave secreta GEMINI_API_KEY, que nunca sale de Cloudflare.
 *
 * Variables (Settings → Variables and Secrets):
 *   GEMINI_API_KEY  (Secret, obligatoria)  tu clave de Google AI Studio
 *   MODEL           (Text, opcional)       por ejemplo gemini-2.5-flash. Si no la pones, usa el Flash más nuevo.
 */

const ORIGENES = ['https://yudaime01515-max.github.io', 'http://localhost:8000', 'http://127.0.0.1:8000'];
const ACCESO_URL = 'https://raw.githubusercontent.com/yudaime01515-max/umbral-analytics/main/public/data/acceso.json';
// Primero los Flash-Lite: son los que el plan gratis deja usar sin trabas y responden rápido.
const MODELOS = ['gemini-flash-lite-latest', 'gemini-2.5-flash-lite', 'gemini-flash-latest', 'gemini-2.5-flash'];
const ESPERA_MS = 20000; // si un modelo tarda más de 20 s, se pasa al siguiente

const INSTRUCCIONES = `Eres "Umbral", el asistente de Umbral Analytics, una web privada de pronósticos deportivos.
Hablas en español de Perú, cercano y directo, como un amigo que sabe de apuestas y de estadística. Respuestas cortas y claras.

REGLAS DE DATOS
- Usa SOLO los partidos, mercados y probabilidades que vienen en DATOS DEL CEREBRO. Nunca inventes partidos, cuotas, alineaciones ni noticias.
- Si te piden algo que no está en los datos (otro deporte, una liga que no aparece, un partido que no está), dilo claro y ofrece lo más parecido que sí haya.
- El porcentaje de cada mercado es la probabilidad del modelo (ya corregida por el cerebro). "justa" es la cuota mínima que debería pagar la casa (1/probabilidad). "casa" es la cuota real de las casas cuando existe.
- La sección CALIBRACIÓN dice cuánto acertó de verdad cada tipo de jugada. Prefiere los tipos que aciertan más en la realidad y avisa si un tipo viene fallando más de lo que promete.
- Respeta el umbral del usuario: no propongas selecciones por debajo de él salvo que pida algo arriesgado o de cuota alta (y entonces avísale).
- Una jugada con más de 92% casi no paga: evítala salvo que la pidan.
- En vivo, usa solo los partidos marcados EN VIVO; su probabilidad ya está recalculada con el marcador.

CÓMO MOSTRAR JUGADAS (muy importante)
- Para una jugada suelta escribe en su propia línea: [[pick:ID|Nombre exacto del mercado]]
- Para una combinada escribe en su propia línea: [[combo:ID|Mercado;ID|Mercado;ID|Mercado]]
- ID y nombre del mercado deben copiarse EXACTOS de los datos. La página dibuja la tarjeta y calcula ella misma la probabilidad total y la cuota, así que NO escribas tú el porcentaje total ni la cuota de una combinada.
- En una combinada usa partidos distintos (una selección por partido), salvo que pidan "combinada del mismo partido".
- Combinada segura: 2 selecciones; normal: 3; cuota alta: 4 o 5. Cada selección suma riesgo.
- Si en los datos aparece "COMBINADA CALCULADA POR LA PÁGINA", esa ya está armada con lo más probable hasta llegar a la cuota pedida: úsala EXACTA (copia su línea [[combo:...]]), no la cambies ni armes otra, y explica por qué es sólida cada selección.
- CUOTA OBJETIVO: si piden una cuota (ej. "cuota 2"), la cuota total es la multiplicación de las cuotas justas de cada selección. Tabla de referencia: cuota 1.5 ≈ 67% total, cuota 2 ≈ 50%, cuota 3 ≈ 33%, cuota 5 ≈ 20%. Calcula paso a paso antes de responder (ej. 1.17 × 1.30 × 1.33 ≈ 2.02) y elige selecciones hasta llegar cerca de la cuota pedida (entre 90% y 120% de ella). Si no llegas, agrega otra selección o cambia a mercados un poco menos probables. Si de verdad no se puede, dilo y da la más cercana.
- Si piden combinada de UN partido, puedes usar varios mercados del mismo partido (resultado, totales, hándicap, jugadores), pero que no se contradigan ni dependan demasiado entre sí.
- Después de cada tarjeta explica en 1 o 2 líneas por qué la elegiste (forma, nivel, cuota con valor, cómo va el partido, acierto del tipo de jugada).

IMÁGENES (capturas de apuestas)
- Si en los datos aparece "ANÁLISIS DEL BOLETO (calculado por la página)", esas probabilidades y la probabilidad total YA están bien calculadas con los datos de Umbral: úsalas tal cual, NO las recalcules ni las cambies. La página ya le muestra al usuario la tabla; tú explica en pocas líneas qué selección es la más floja y por qué, si la cuota de la casa tiene valor y qué cambiarías (con [[pick:ID|Mercado]] si propones otra).
- Si el usuario manda una imagen, primero lista lo que lees: cada selección con su partido, mercado, línea y cuota de la casa.
- Busca cada selección en los datos. "Primer tiempo" = "1.ª mitad", "Segundo tiempo" = "2.ª mitad". Si la línea exacta no está, estima con las dos líneas más cercanas (ej. si "más de 109.5" es 60% y "más de 113.5" es 50%, entonces "más de 110.5" ≈ 57.5%) y dilo.
- Para cada selección da: probabilidad de Umbral, cuota justa (1/probabilidad) y si la cuota de la casa tiene valor (cuota casa × probabilidad > 1 = tiene valor).
- Probabilidad de la combinada = multiplica las probabilidades de todas las selecciones. Hazlo paso a paso (ej. 0.575 × 0.52 × 0.55 = 0.164 → 16%). La cuota de la casa total es la multiplicación de sus cuotas.
- Da un veredicto claro: si conviene, si hay alguna selección floja que quitarías o cambiarías por otra mejor del mismo partido (muéstrala con [[pick:ID|Mercado]]).
- Si algún partido de la imagen no está en los datos, dilo y no inventes su probabilidad.

RESPONSABILIDAD
- Nada es una fija: son probabilidades. Nunca uses las palabras "fija", "segura" ni "seguro" para prometer un resultado (puedes decir "la más probable" o "la más sólida"). Recuerda de vez en cuando (sin sermonear) que una unidad es 1% a 2% de lo que tiene para apostar y que no conviene perseguir pérdidas.
- Si la casa paga menos que la cuota justa, recomienda no jugarla.`;

const LEER_IMAGEN = `Lees capturas de boletos o combinadas de casas de apuestas (Betano, Te Apuesto, Inkabet, Bet365, etc.).
Devuelve SOLO un JSON con esta forma:
{"selecciones":[{"local":"equipo local completo","visitante":"equipo visitante completo","mercado":"nombre en formato Umbral","cuota":1.47,"texto":"lo que dice la imagen"}],"cuota_total":3.88}

Escribe "mercado" con estos formatos EXACTOS de Umbral (usa el nombre completo del equipo o jugador tal como sale en la imagen):
- Ganador: "Gana {Equipo}" · "Empate"
- Total del partido: "Más de {L} puntos" / "Menos de {L} puntos" (básquet) · "Más de {L} goles" / "Menos de {L} goles" (fútbol)
- Total de UN equipo (cuando dice "Total de puntos {Equipo}" o "{Equipo} total"): "{Equipo}: más de {L} puntos" / "{Equipo}: menos de {L} puntos"
- Medio tiempo / primer tiempo / 1.ª mitad: "1.ª mitad: más de {L} puntos" · "1.ª mitad: gana {Equipo}" · segundo tiempo: "2.ª mitad: ..."
- Cuartos: "1.er cuarto: más de {L} puntos" · "2.º cuarto: ..." · "3.er cuarto: ..." · "4.º cuarto: ..."
- Hándicap: "{Equipo} -4.5" · "{Equipo} +4.5"
- Fútbol: "Ambos marcan: sí" · "Ambos marcan: no" · "Doble oportunidad: {Equipo} o empate" · "Doble oportunidad: no hay empate" · "Más de {L} córners" · "Más de {L} tarjetas amarillas"
- Jugador: "{Jugador}: más de {L} puntos" (o rebotes, asistencias, triples)
- Esports: "Gana {Equipo}" · "{Equipo} gana al menos un mapa" · "Más de {L} mapas"
Si no estás seguro de un dato, igual ponlo como mejor lo entiendas. No inventes selecciones que no estén en la imagen. cuota_total es la cuota total que muestra la imagen (o null).`;

function cors(origin) {
  const ok = ORIGENES.includes(origin) ? origin : ORIGENES[0];
  return {
    'Access-Control-Allow-Origin': ok,
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Max-Age': '86400',
    'Vary': 'Origin',
  };
}

function responder(obj, status, origin) {
  return new Response(JSON.stringify(obj), {
    status: status || 200,
    headers: Object.assign({ 'Content-Type': 'application/json; charset=utf-8' }, cors(origin)),
  });
}

async function codigoValido(h) {
  if (typeof h !== 'string' || !/^[0-9a-f]{64}$/.test(h)) return false;
  const r = await fetch(ACCESO_URL, { cf: { cacheTtl: 60, cacheEverything: true } });
  if (!r.ok) return false;
  const d = await r.json();
  return (d.codigos || []).some((c) => c.h === h);
}

// Pregunta a Gemini probando los modelos en orden; devuelve {text, model} o {err, status}
async function preguntar(env, pedido) {
  const modelos = [env.MODEL, ...MODELOS].filter((m, i, a) => m && a.indexOf(m) === i);
  let ultimo = '';
  for (const modelo of modelos) {
    let r;
    try {
      r = await fetch('https://generativelanguage.googleapis.com/v1beta/models/' + modelo + ':generateContent', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': env.GEMINI_API_KEY },
        body: JSON.stringify(pedido),
        signal: AbortSignal.timeout(ESPERA_MS),
      });
    } catch (e) {
      ultimo = modelo + ' tardó demasiado';
      continue;
    }
    if (r.ok) {
      const d = await r.json();
      const c = d.candidates && d.candidates[0];
      const texto = c && c.content && (c.content.parts || []).filter((p) => p.text && !p.thought).map((p) => p.text).join('');
      if (texto) return { text: texto, model: modelo };
      ultimo = 'La IA no devolvió texto (' + ((c && c.finishReason) || 'sin motivo') + ').';
      continue;
    }
    ultimo = r.status + ' ' + (await r.text()).slice(0, 300);
    // 404: el modelo no existe para esta clave · 429: se acabó el límite gratis de ese modelo · 5xx: Google ocupado
    if (r.status === 404 || r.status === 429 || r.status >= 500) continue;
    if (r.status === 400 || r.status === 403) return { status: 502, err: { error: 'Google rechazó el pedido. Revisa tu clave GEMINI_API_KEY.', detalle: ultimo } };
  }
  const limite = /429|RESOURCE_EXHAUSTED/.test(ultimo);
  return { status: limite ? 429 : 502, err: { error: limite ? 'Se acabó el límite gratis de la IA por ahora. Prueba en un rato.' : 'La IA no respondió.', detalle: ultimo, limite } };
}

export default {
  async fetch(req, env) {
    const origin = req.headers.get('Origin') || '';
    if (req.method === 'OPTIONS') return new Response(null, { status: 204, headers: cors(origin) });
    if (req.method === 'GET') return responder({ ok: true, msg: 'Intermediario de Umbral funcionando.', clave: !!env.GEMINI_API_KEY }, 200, origin);
    if (req.method !== 'POST') return responder({ error: 'Método no permitido' }, 405, origin);
    if (origin && !ORIGENES.includes(origin)) return responder({ error: 'Origen no permitido' }, 403, origin);
    if (!env.GEMINI_API_KEY) return responder({ error: 'Falta la clave GEMINI_API_KEY en Cloudflare.' }, 500, origin);

    let body;
    try { body = await req.json(); } catch (e) { return responder({ error: 'Pedido inválido' }, 400, origin); }
    if (!(await codigoValido(body.code))) return responder({ error: 'Tu código de acceso ya no es válido.' }, 401, origin);

    const img = body.image;
    const imgOk = img && typeof img.data === 'string' && /^image\/(jpeg|png|webp)$/.test(img.mime || '') && img.data.length < 4000000;

    // Paso 1 con imagen: la IA solo LEE el boleto; las cuentas las hace la página con los datos de Umbral
    if (body.mode === 'leer') {
      if (!imgOk) return responder({ error: 'Falta la imagen.' }, 400, origin);
      const res = await preguntar(env, {
        systemInstruction: { parts: [{ text: LEER_IMAGEN }] },
        contents: [{ role: 'user', parts: [{ inlineData: { mimeType: img.mime, data: img.data } }, { text: 'Lee esta imagen y devuelve el JSON.' }] }],
        generationConfig: { temperature: 0, maxOutputTokens: 2048, responseMimeType: 'application/json' },
      });
      if (!res.text) return responder(res.err, res.status, origin);
      let out = null;
      try { out = JSON.parse(res.text.replace(/^```(?:json)?|```$/g, '').trim()); } catch (e) { out = null; }
      if (!out || !Array.isArray(out.selecciones)) return responder({ error: 'No pude leer las selecciones de la imagen.' }, 422, origin);
      return responder({ selecciones: out.selecciones.slice(0, 20), cuota_total: out.cuota_total || null, model: res.model }, 200, origin);
    }

    const contexto = String(body.context || '').slice(0, 240000);
    const msgs = (Array.isArray(body.messages) ? body.messages : []).slice(-12)
      .filter((m) => m && typeof m.text === 'string' && m.text.trim())
      .map((m) => ({ role: m.role === 'bot' ? 'model' : 'user', parts: [{ text: m.text.slice(0, 4000) }] }));
    if (!msgs.length || msgs[msgs.length - 1].role !== 'user') return responder({ error: 'Falta tu mensaje.' }, 400, origin);
    while (msgs.length && msgs[0].role !== 'user') msgs.shift();
    // imagen adjunta (captura de una apuesta): va junto al último mensaje del usuario
    if (imgOk) {
      msgs[msgs.length - 1].parts.unshift({ inlineData: { mimeType: img.mime, data: img.data } });
    }

    const pedido = {
      systemInstruction: { parts: [{ text: INSTRUCCIONES + '\n\n=== DATOS DEL CEREBRO ===\n' + contexto }] },
      contents: msgs,
      generationConfig: { temperature: 0.5, maxOutputTokens: 4096 },
    };

    const res = await preguntar(env, pedido);
    if (res.text) return responder({ text: res.text, model: res.model }, 200, origin);
    return responder(res.err, res.status, origin);
  },
};
