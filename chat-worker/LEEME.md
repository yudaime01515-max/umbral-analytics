# Conectar la IA al chat de Umbral (gratis)

El chat ya funciona en la página en **modo básico**. Para que responda como una IA de verdad hay que hacer dos cosas una sola vez:
crear una clave gratis de Gemini y poner el "intermediario" en Cloudflare (guarda la clave para que nadie la vea).

## 1. Clave gratis de Gemini

1. Entra a https://aistudio.google.com/apikey con tu cuenta de Google.
2. Presiona **Create API key** (Crear clave de API). Si te pide un proyecto, elige o crea uno cualquiera.
3. Copia la clave (empieza con `AIza…`). **No la compartas con nadie ni la pegues en GitHub.**

## 2. Intermediario en Cloudflare

1. Crea una cuenta gratis en https://dash.cloudflare.com/sign-up (no pide tarjeta).
2. En el menú de la izquierda: **Compute (Workers)** → **Workers & Pages** → **Create** → **Start with Hello World!**
3. Ponle de nombre `umbral-chat` y presiona **Deploy**.
4. Presiona **Edit code**. Borra todo lo que hay y pega el contenido completo del archivo `chat-worker/worker.js` de este repositorio. Presiona **Deploy**.
5. Vuelve al Worker → **Settings** → **Variables and Secrets** → **Add**:
   - Type: **Secret**
   - Name: `GEMINI_API_KEY`
   - Value: la clave de Gemini del paso 1
   - **Deploy**
6. Copia el enlace del Worker (algo como `https://umbral-chat.TU-USUARIO.workers.dev`). Ábrelo en el navegador: debe decir `"clave":true`.

## 3. Avisar el enlace

Pásale el enlace del Worker a Claude para que lo ponga en la página.
Si lo haces tú: en `public/index.html` busca `var CHAT_API='';` y pon el enlace entre las comillas.

## Notas

- El chat solo responde a quien tenga un código válido de `data/acceso.json`. Si borras a alguien, deja de poder usar la IA.
- Si se acaba el límite gratis de Gemini del día, el chat sigue funcionando en modo básico y avisa.
- Para usar otro modelo, agrega en Cloudflare una variable de texto `MODEL` (por ejemplo `gemini-2.5-flash`).
