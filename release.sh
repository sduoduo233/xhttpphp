#!/bin/bash

rm release.zip || true
7z a release.zip README.md worker.php common.php xhttp.php index.php LICENSE .htaccess