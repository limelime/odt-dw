#!/bin/bash
set -e
# Description: Deploy lastest version of plugin-odt

PLUGIN_DIR=/media/master/github/mywiki/lib/plugins
PLUGIN_NAME=odt
ODT_DIR="${PLUGIN_DIR}/${PLUGIN_NAME}"

# Deploy
mkdir -p "${ODT_DIR}"
yes | cp -vR * "${ODT_DIR}"
