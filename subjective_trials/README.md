# Subjective A/B Trials Web App

Web app for running subjective A/B evaluation trials of image manipulation methods.

## Usage

1. **Configure environment**:

   ```bash
   cd subjective_trials_web_app
   cp .env.example .env
   ```

   Edit `.env` to set ports, database name, and credentials. Defaults are fine for a local run.

2. **Seed the stimulus images**:
   Place the images into [subjective_trials_web_app/src/images/](subjective_trials_web_app/src/images/), organized one directory per **scene**:

   ```
   src/images/
   ├── <scene_id>/
   │   ├── <image_id>.png
   │   ├── <image_id>.jpg
   │   └── ...
   └── ...
   ```

   A *scene* is one location / sample; all images of the same scene are different renderings of the same content, and the app evaluates them as a group. The trial works as a round-robin over all images within each scene: every unordered pair of images from the same scene is shown to the participant as an A/B comparison, and the app derives a preference (frequency) matrix per scene from the pairwise choices.

   The image filenames (without extension) act as image IDs; the directory name is the scene ID. Both are picked up automatically.

   The default images in the repo are the outputs of the finetuned baseline architecture, and are the exact image set used in the subjective evaluation described in the paper.
3. **Start the stack:**

   ```bash
   docker compose up -d --build
   ```

   This starts four services:

   | Service     | Purpose                              |
   |-------------|--------------------------------------|
   | `caddy`     | Reverse proxy / HTTPS termination    |
   | `trials-app-db`  | MariaDB 10.11 database (auto-initialized from [src/database_init.sql](subjective_trials_web_app/src/database_init.sql) on first start) |
   | `trials-app`| PHP 8.2 Apache app serving the trial UI |
   | `adminer`   | DB admin UI (port `ADMINER_PORT`, default 8080) |

4. **Seed user access keys:**

   The app requires participants to authenticate with an access key, can be used to differentiate participants in the database. Keys must be seeded manually into the `api_keys` table, e.g. via Adminer: `INSERT INTO api_keys (key_value, note, mobile_allowed) VALUES ('some-key', 'participant label', 0);`. Optional per-key fields: `forced_variant` (1 or 2 to override the random A/B variant assignment) and `mobile_allowed`.

   Adminer is available at `http://localhost:8080` for inspecting the database (server: `trials-app-db`, credentials from `.env`).

5. **Open the app:**

   Navigate to `http://localhost` (on the port set via `TRIALS_APP_HTTP_PORT`).

6. **Run trials:**

   Stimulus images are served from [src/images/](subjective_trials_web_app/src/images/) (mounted read-only into the app container).

## Results

All participant responses are stored in the MariaDB database (see the [ER diagram](subjective_trials_web_app/documentation/db_er_diagram.pdf)).

The CSV files in [results/](results/) ([trials_public.csv](results/trials_public.csv), [trials_thermofisher.csv](results/trials_thermofisher.csv)) were extracted from the database after the trial runs conducted for the subjective evaluation in the paper.