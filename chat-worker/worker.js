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
const MODELOS = ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-flash-lite-latest', 'gemini-2.5-flash-lite'];

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
- Después de cada tarjeta explica en 1 o 2 líneas por qué la elegiste (forma, nivel, cuota con valor, cómo va el partido, acierto del tipo de jugada).

RESPONSABILIDAD
- Nada es una fija: son probabilidades. Recuerda de vez en cuando (sin sermonear) que una unidad es 1% a 2% de lo que tiene para apostar y que no conviene perseguir pérdidas.
- Si la casa paga menos que la cuota justa, recomienda no jugarla.`;

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

    const contexto = String(body.context || '').slice(0, 240000);
    const msgs = (Array.isArray(body.messages) ? body.messages : []).slice(-12)
      .filter((m) => m && typeof m.text === 'string' && m.text.trim())
      .map((m) => ({ role: m.role === 'bot' ? 'model' : 'user', parts: [{ text: m.text.slice(0, 4000) }] }));
    if (!msgs.length || msgs[msgs.length - 1].role !== 'user') return responder({ error: 'Falta tu mensaje.' }, 400, origin);
    while (msgs.length && msgs[0].role !== 'user') msgs.shift();

    const pedido = {
      systemInstruction: { parts: [{ text: INSTRUCCIONES + '\n\n=== DATOS DEL CEREBRO ===\n' + contexto }] },
      contents: msgs,
      generationConfig: { temperature: 0.5, maxOutputTokens: 4096 },
    };

    const modelos = [env.MODEL, ...MODELOS].filter((m, i, a) => m && a.indexOf(m) === i);
    let ultimo = '';
    for (const modelo of modelos) {
      const r = await fetch('https://generativelanguage.googleapis.com/v1beta/models/' + modelo + ':generateContent', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': env.GEMINI_API_KEY },
        body: JSON.stringify(pedido),
      });
      if (r.ok) {
        const d = await r.json();
        const c = d.candidates && d.candidates[0];
        const texto = c && c.content && (c.content.parts || []).filter((p) => p.text && !p.thought).map((p) => p.text).join('');
        if (texto) return responder({ text: texto, model: modelo }, 200, origin);
        ultimo = 'La IA no devolvió texto (' + ((c && c.finishReason) || 'sin motivo') + ').';
        continue;
      }
      ultimo = r.status + ' ' + (await r.text()).slice(0, 300);
      // 404: el modelo no existe para esta clave · 429: se acabó el límite gratis de ese modelo · 5xx: Google ocupado
      if (r.status === 404 || r.status === 429 || r.status >= 500) continue;
      if (r.status === 400 || r.status === 403) return responder({ error: 'Google rechazó el pedido. Revisa tu clave GEMINI_API_KEY.', detalle: ultimo }, 502, origin);
    }
    const limite = /429|RESOURCE_EXHAUSTED/.test(ultimo);
    return responder({ error: limite ? 'Se acabó el límite gratis de la IA por ahora. Prueba en un rato.' : 'La IA no respondió.', detalle: ultimo, limite }, limite ? 429 : 502, origin);
  },
};
