"""release-only bundle"""
from __future__ import annotations
import argparse
import pathlib
import re
import shutil
import sys
RUNTIME_DIRECTORY = "config/app/runtime"
WORKER_DIRECTORY = "config/app/workers"
FRONTEND_DIRECTORY = "config/www"
NGINX_FILES = {
    "nginx/nginx.conf", "nginx/resolver.conf", "nginx/ssl.conf",
    "nginx/worker_processes.conf", "nginx/php/php-local.ini",
    "nginx/php/www2.conf", "nginx/site-confs/default.conf",
}
CONFIG_FILES_REQUIRED = {
    "config/app/config/blocklist.json",
    "config/app/config/config_default.php",
    "config/app/config/database/migrate.php",
    "config/app/config/defaults.php",
    "config/app/config/monitoring-drivers.php",
    "config/app/config/service.php",
    "config/app/config/session.php",
    "config/app/config/whatsapp-gateways.php",
    "config/app/default/error.txt",
    "config/app/default/logs_velue.txt",
    "config/app/default/toats_message.txt",
}
CONFIG_FILES = CONFIG_FILES_REQUIRED | {
    "config/app/config/public-entry.php",
}
SERVICES = {
    "linnms-access-refresh", "linnms-cleaner", "linnms-cli-broker",
    "linnms-courier-1", "linnms-initial-reader", "linnms-notifier",
    "linnms-reader-1", "linnms-reader-2", "linnms-reader-3",
    "linnms-sampler-1", "linnms-writer-1", "linnms-workers",
}
WEB_SUFFIXES = {".php", ".html", ".css", ".js", ".ico", ".png", ".jpg", ".jpeg", ".svg", ".woff", ".woff2", ".webp", ".gif", ".json"}
RUNTIME_SUFFIXES = {".php", ".json"}
FORBIDDEN_COMPONENTS = {".git", ".github", "docker", "doc", "tests", "test", "tools", "source-seed", "__pycache__"}
FORBIDDEN_SUFFIXES = {".sample", ".bak", ".orig", ".log", ".tmp", ".temp"}
REQUIRED = CONFIG_FILES_REQUIRED | {
    "config/app/runtime/app/paths.php",
    "config/app/runtime/backend/api.php",
    "config/app/workers/access-ready.php",
    "config/app/workers/worker.php",
    "config/www/entrypoints/index.php",
    "nginx/nginx.conf",
    "nginx/site-confs/default.conf",
    "linnms.init.d/50-linnms-public-init",
}

CONFIG_SEED_BLOCK = r'''# Persistent configuration
if [ -L "$APP_ROOT/config" ]; then
  echo "[LINNMS] Configuration directory must be a real directory." >&2
  exit 1
fi
mkdir -p "$APP_ROOT/config" || exit 1
chown abc:abc "$APP_ROOT/config" || exit 1
chmod 0750 "$APP_ROOT/config" || exit 1

seed_config_file() {
  seed_name="$1"
  seed_source="$2"
  seed_target="$APP_ROOT/config/$seed_name"
  if [ -L "$seed_target" ]; then
    if [ -d "$seed_target" ]; then
      echo "[LINNMS] Invalid configuration link: $seed_name" >&2
      return 1
    fi
    seed_link="$(readlink "$seed_target")" || return 1
    case "$seed_link" in
      "/opt/linnms/config/$seed_name"|"/opt/linnms/config-seed/$seed_name"|*/config/app/config/"$seed_name")
        if [ -f "$seed_target" ] && [ -s "$seed_target" ]; then
          seed_source="$seed_target"
        fi
        ;;
      *)
        if [ ! -f "$seed_target" ] || [ ! -s "$seed_target" ]; then
          echo "[LINNMS] Cannot recover configuration link: $seed_name" >&2
          return 1
        fi
        seed_source="$seed_target"
        ;;
    esac
  elif [ -e "$seed_target" ]; then
    if [ ! -f "$seed_target" ]; then
      echo "[LINNMS] Invalid configuration file: $seed_name" >&2
      return 1
    fi
    if [ -s "$seed_target" ]; then
      chown abc:abc "$seed_target" && chmod 0640 "$seed_target"
      return $?
    fi
  fi
  if [ ! -f "$seed_source" ] || [ ! -s "$seed_source" ]; then
    echo "[LINNMS] Missing configuration template: $seed_name" >&2
    return 1
  fi
  seed_tmp="$(mktemp "$APP_ROOT/config/.seed.XXXXXX")" || return 1
  if ! cp -L "$seed_source" "$seed_tmp" ||
     ! chmod 0640 "$seed_tmp" ||
     ! chown abc:abc "$seed_tmp" ||
     ! mv -f "$seed_tmp" "$seed_target"; then
    rm -f "$seed_tmp"
    return 1
  fi
}

for config_file in blocklist.json config_default.php defaults.php monitoring-drivers.php service.php session.php whatsapp-gateways.php; do
  seed_config_file "$config_file" "$CONFIG_TEMPLATE/$config_file" || exit 1
done

for message_file in error.txt logs_velue.txt toats_message.txt; do
  message_template="$DEFAULT_IMAGE/$message_file"
  legacy_message="$APP_ROOT/default/$message_file"
  if [ -f "$legacy_message" ] && [ -s "$legacy_message" ]; then
    message_template="$legacy_message"
  fi
  seed_config_file "$message_file" "$message_template" || exit 1
done

# End persistent configuration
'''

def permitted(rel: pathlib.PurePosixPath) -> bool:
    name = rel.name
    s = rel.as_posix()
    if any(part.startswith(".") or part.lower() in FORBIDDEN_COMPONENTS for part in rel.parts):
        return False
    if any(name.lower().endswith(x) for x in FORBIDDEN_SUFFIXES):
        return False
    if s in NGINX_FILES or s in CONFIG_FILES:
        return True
    if len(rel.parts) == 2 and rel.parts[0] == "linnms.service.d" and name in SERVICES:
        return True
    if s == "linnms.init.d/50-linnms-public-init":
        return True
    if s.startswith(RUNTIME_DIRECTORY + "/workers/"):
        return False
    if s.startswith((RUNTIME_DIRECTORY + "/", WORKER_DIRECTORY + "/")) and rel.suffix.lower() in RUNTIME_SUFFIXES:
        return True
    if s.startswith(FRONTEND_DIRECTORY + "/") and rel.suffix.lower() in WEB_SUFFIXES:
        return True
    return False

def init(text: str) -> str:
    """Remove"""
    start_marker = 'if [ "$SOURCE_MOUNTS" = "1" ]; then\n'
    start = text.find(start_marker)
    else_marker = '\nelse\n  CONFIG_TEMPLATE="$CONFIG_IMAGE"'
    branch_start = text.find(else_marker, start)
    end_marker = '\nfi\n\nif [ -L /config/nginx/log ]; then'
    branch_end = text.find(end_marker, branch_start)
    if start == -1 or branch_start == -1 or branch_end == -1:
        raise ValueError("E20")
    branch = text[branch_start + len('\nelse\n'):branch_end]
    text = text[:start] + branch + text[branch_end + len('\nfi\n'):]
    text = re.sub(r'^SOURCE_ROOT=.*\n|^SOURCE_SEED=.*\n|^SOURCE_MOUNTS=.*\n',
                  '', text, flags=re.MULTILINE)
    chown_block = ('if [ "$SOURCE_MOUNTS" = "1" ]; then\n'
                   '  chown -R abc:users "$SOURCE_ROOT/config/www"\n'
                   'fi\n\n')
    if text.count(chown_block) != 1:
        raise ValueError("E21")
    text = text.replace(chown_block, '')
    rejected = ("SOURCE_MOUNTS", "SOURCE_ROOT", "SOURCE_SEED",
                "source-seed", "Dockerfile.staging", "LINNMS_SOURCE_MOUNTS",
                "staging", "/workspace/linnms-source")
    if any(term.lower() in text.lower() for term in rejected):
        raise ValueError("E22")
    # Keep local settings independent of the imported source layout.
    text = text.replace('CONFIG_IMAGE=/opt/linnms/config\n',
                        'CONFIG_IMAGE=/opt/linnms/config-seed\nDEFAULT_IMAGE=/opt/linnms/app/default\n', 1)
    seed_start = text.find('for message_file in toats_message.txt error.txt logs_velue.txt; do\n')
    seed_end = text.find('\nfind /config/nginx -mindepth 1', seed_start)
    if seed_start == -1 or seed_end == -1 or 'DEFAULT_IMAGE=' not in text:
        raise ValueError("E28")
    text = text[:seed_start] + CONFIG_SEED_BLOCK + text[seed_end:]
    text = text.replace('chown root:root "$APP_ROOT/config"\nchmod 0755 "$APP_ROOT/config"\n',
                        'chown abc:abc "$APP_ROOT/config"\nchmod 0750 "$APP_ROOT/config"\n')
    header = '#!/usr/bin/with-contenv sh\n'
    # Source may use a plain POSIX shebang and either strict shell mode.
    # Replace only the known source header; never carry /bin/sh into the
    # LinuxServer public init, which must run through with-contenv.
    shell_header = '#!/bin/sh\n'
    known_modes = ('set -u\n', 'set -eu\n')
    if any(text.startswith(shell_header + mode) for mode in known_modes):
        text = header + text[len(shell_header):]
    elif text.startswith(known_modes):
        text = header + text
    if not text.startswith(header):
        raise ValueError("E23")
    return text

def adjust_file(rel: str, text: str) -> str:
    if rel == "config/app/runtime/app/paths.php":
        if "'workers' =>" not in text:
            marker = "        'config' => dirname(LINNMS_ROOT),"
            if marker not in text:
                raise ValueError("E29")
            text = text.replace(marker, marker + "\n        'workers' => dirname(LINNMS_ROOT),", 1)
    elif rel == "config/www/app/helpers/toast-message.php":
        text = text.replace("'/config/app/default/toats_message.txt'", "'/config/app/config/toats_message.txt'")
    elif rel == "config/app/runtime/src/helpers/log-message.php":
        text = text.replace("'/config/app/default'", "'/config/app/config'")
    return text

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=pathlib.Path)
    parser.add_argument("output", type=pathlib.Path)
    args = parser.parse_args()
    source = args.source.resolve(strict=True)
    dest = args.output.resolve()
    if dest.exists() or dest.is_relative_to(source) or source.is_relative_to(dest):
        raise SystemExit("E24")
    missing = sorted(item for item in REQUIRED if not (source / item).is_file())
    if missing:
        raise SystemExit("E25")
    dest.mkdir(parents=True, mode=0o700)
    file_count = 0
    for path in source.rglob("*"):
        rel = pathlib.PurePosixPath(path.relative_to(source).as_posix())
        if path.is_symlink():
            if permitted(rel):
                raise SystemExit("E26")
            continue
        if path.is_file() and permitted(rel):
            target = dest.joinpath(*rel.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            if rel.as_posix() == "linnms.init.d/50-linnms-public-init":
                target.write_text(init(path.read_text(encoding="utf-8")), encoding="utf-8")
            elif rel.as_posix() in {"config/app/runtime/app/paths.php",
                                     "config/www/app/helpers/toast-message.php",
                                     "config/app/runtime/src/helpers/log-message.php"}:
                target.write_text(adjust_file(rel.as_posix(), path.read_text(encoding="utf-8")), encoding="utf-8")
            else:
                shutil.copyfile(path, target)
            if rel.parts[0] in {"linnms.init.d", "linnms.service.d"}:
                target.chmod(0o755)
            file_count += 1
    print(f"OK {file_count}")
    assert file_count > 250, "E27"

if __name__ == "__main__":
    main()
