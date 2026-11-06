# Umbral Analytics en GitHub + Netlify (gratis)

GitHub ejecuta la actualización cada 3 horas con tu clave guardada en secreto, y Netlify publica la web cada vez que cambian los datos.

## 1. Subir los archivos a GitHub

1. Entra a github.com y presiona **New** (repositorio nuevo).
2. Nombre: `umbral-analytics`. Elige **Private**. Presiona **Create repository**.
3. En la página del repositorio, haz clic en **"uploading an existing file"**.
4. Abre la carpeta `umbral-analytics` en tu computadora, selecciona **todo lo que hay adentro** (no la carpeta en sí) y arrástralo a GitHub.
5. Abajo presiona **Commit changes**.
6. Comprueba que se ve la carpeta `.github`. Si no aparece, mira el punto "Si no se subió .github" al final.

## 2. Guardar tu clave de API-Sports como secreto

1. En el repositorio: **Settings → Secrets and variables → Actions**.
2. **New repository secret**.
3. Name: `API_SPORTS_KEY`. Secret: pega tu clave. **Add secret**.

## 3. Primera actualización con datos reales

1. Pestaña **Actions**. Si pide permiso, presiona el botón verde para activar los workflows.
2. A la izquierda, **Actualizar pronósticos** → **Run workflow** → **Run workflow**.
3. Espera 1 o 2 minutos hasta que salga el visto verde. Entra al trabajo y abre el paso "Calcular pronósticos" para ver el registro: cada liga debe decir `OK ... partidos`.

## 4. Conectar Netlify

1. En Netlify: **Add new project → Import an existing project → GitHub**.
2. Autoriza a Netlify y elige el repositorio `umbral-analytics`.
3. No cambies nada (el archivo `netlify.toml` ya le dice que publique la carpeta `public`). Presiona **Deploy**.
4. Cambia el nombre del enlace en Project configuration.

Desde ahí, cada 3 horas GitHub actualiza los datos y Netlify vuelve a publicar solo.

## Si no se subió .github

Windows a veces no arrastra esa carpeta. Créala a mano:

1. En el repositorio: **Add file → Create new file**.
2. En el nombre escribe exactamente: `.github/workflows/actualizar.yml`
3. Pega el contenido del archivo `actualizar.yml` que viene en la carpeta `.github/workflows` de este ZIP.
4. **Commit changes**.

## Cambiar ligas o umbral

Edita `config.php` directamente en GitHub (lápiz de editar), guarda, y en la siguiente actualización se aplica.
