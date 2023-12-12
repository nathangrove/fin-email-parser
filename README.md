# Financial Email Parser

Parses emails from finanical services and update FireFlyIII with the transactions.

# Getting started

- Copy .env.example to .env and populate its data.
- Update any parsing logic for your specific emails.
- Run: `bash scripts/build.sh` to build the image.
- Run `docker run -d --env-file .env -v $(pwd):/app fin-email-parser:latest`

# Debugging

- Run: `bash scripts/run.sh` to start the docker container.
