#!/usr/bin/env bash
set -euo pipefail

cli=$1
name=$2
image=$3
connection_args=()
if [[ -n "${CONTAINER_CONNECTION:-}" ]]; then
	connection_args=(--connection "$CONTAINER_CONNECTION")
fi

php "$cli" "${connection_args[@]}" image-pull "$image" >/dev/null
php "$cli" "${connection_args[@]}" create --name "$name" --image "$image" \
	--label org.opensim-engine.test=bash -- sh -c 'while :; do sleep 3600; done' >/dev/null
php "$cli" "${connection_args[@]}" start "$name" >/dev/null
php "$cli" "${connection_args[@]}" running "$name"
