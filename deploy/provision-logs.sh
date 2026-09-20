#!/usr/bin/env bash
set -euo pipefail

# Preserve contents and inodes while making both the worker and Apache able to append.
log_root=/var/log/lorkhanserver
install -d -o lorkhan -g www-data -m 0750 "${log_root}"
for name in lorkhan.log context_sent_to_llm.log context_sent_to_llm_fast.log output_from_llm.log output_from_llm_fast.log output_to_plugin.log stt.log; do
    log_file="${log_root}/${name}"
    if [[ -L ${log_file} || ( -e ${log_file} && ! -f ${log_file} ) ]]; then
        echo "Refusing non-regular log: ${log_file}" >&2
        exit 1
    fi
    touch -- "${log_file}"
    chown lorkhan:www-data -- "${log_file}"
    chmod 0660 -- "${log_file}"
done
