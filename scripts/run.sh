#!/bin/bash

docker run --rm -it --env-file .env -v $(pwd):/app fin-email-parser:latest php /app/runner.php 1