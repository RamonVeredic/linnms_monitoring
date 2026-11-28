import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
FILTER = ROOT / "scripts" / "release.py"
VERIFY = ROOT / "scripts" / "verify_context.py"

INIT = '''#!/usr/bin/with-contenv sh
set -eu
CONFIG_IMAGE=/opt/linnms/settings-seed
SOURCE_ROOT=/workspace/linnms-source
SOURCE_SEED=/opt/linnms/source-seed
SOURCE_MOUNTS="${LINNMS_SOURCE_MOUNTS:-0}"

if [ "$SOURCE_MOUNTS" = "1" ]; then
  echo staging-start
else
  CONFIG_TEMPLATE="$CONFIG_IMAGE"
  NGINX_TEMPLATE="$NGINX_IMAGE"
fi

if [ -L /config/nginx/log ]; then
  echo hello
fi
# Persistent configuration
seed_config_file() {
  echo "$1"
}
for config_file in blocklist.json config_default.php defaults.php monitoring-drivers.php service.php session.php whatsapp-gateways.php error.txt logs_velue.txt toats_message.txt; do
  config_source="$CONFIG_TEMPLATE/$config_file"
  seed_config_file "$config_file" "$config_source" || exit 1
done
# End persistent configuration
find /config/nginx -mindepth 1 -maxdepth 1 ! -name log -exec rm -rf -- {} +
if [ "$SOURCE_MOUNTS" = "1" ]; then
  chown -R abc:users "$SOURCE_ROOT/config/www"
fi

echo release-ready
'''

class ReleaseFilterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name)
        self.stage = self.root / "staging"
        self.stage.mkdir()
        self.output = self.root / "plain"
        self._write("linnms.init.d/50-linnms-public-init", INIT)
        self._write("config/app/runtime/app/paths.php", "<?php\n    $path = match ($first) {\n        'config' => '/config/app',\n        'workers' => dirname(LINNMS_ROOT),\n    };")
        self._write("config/app/runtime/backend/api.php", "<?php echo 1;")
        self._write("config/app/runtime/src/example.php", "<?php echo 1;")
        self._write("config/app/workers/access-ready.php", "<?php echo 1;")
        self._write("config/app/workers/worker.php", "<?php echo 1;")
        self._write("config/app/runtime/workers/worker.php", "<?php echo 2;")
        self._write("config/app/settings/config_default.php", "<?php class LinnmsDefaults {}")
        self._write("config/app/settings/defaults.php", "<?php return [];")
        for name in ("monitoring-drivers.php", "service.php", "session.php", "whatsapp-gateways.php"):
            self._write(f"config/app/settings/{name}", "<?php return [];")
        self._write("config/app/settings/database/migrate.php", "<?php exit(0);")
        self._write("config/app/settings/blocklist.json", "{}")
        self._write("config/app/settings/database.php", "PRIVATE_DB")
        for name in ("error.txt", "logs_velue.txt", "toats_message.txt"):
            self._write(f"config/app/settings/{name}", "test.message=Example")
        self._write("config/www/entrypoints/index.php", "<?php echo 1;")
        self._write("config/www/app/helpers/toast-message.php", "<?php $p = '/config/app/settings/toats_message.txt';")
        self._write("config/app/runtime/src/helpers/log-message.php", "<?php $p = '/config/app/settings';")
        self._write("nginx/nginx.conf", "user abc;")
        self._write("nginx/site-confs/default.conf", "root /config/www;")
        self._write("docker/Dockerfile.staging", "FROM example")
        self._write(".github/workflows/build-staging.yml", "on: push")
        self._write("doc/notes.md", "for development only")
        self._write("linnms.service.d/linnms-reader-1", "#!/bin/sh\necho work")
        self._write("linnms.service.d/linnms-sampler-1", "#!/bin/sh\necho sampler")
        self._write("config/www/assets/pages/dashboard/assets.json", '{"styles":[],"scripts":[]}')
        for i in range(25):
            self._write(f"config/app/runtime/vendor/driver/example{i}.php", "<?php echo 1;")
        for i in range(255):
            self._write(f"config/app/runtime/app/example{i}.php", "<?php echo 1;")

    def _write(self, filename, data):
        file = self.stage / filename
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(data)

    def test_filters_staging_build_artifacts(self):
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)],
                       check=True)
        self.assertFalse((self.output / "docker").exists())
        self.assertFalse((self.output / ".github").exists())
        self.assertFalse((self.output / "doc").exists())
        init = (self.output / "linnms.init.d/50-linnms-public-init").read_text()
        self.assertNotIn("SOURCE_MOUNTS", init)
        self.assertNotIn("staging", init)
        self.assertIn('CONFIG_TEMPLATE="$CONFIG_IMAGE"', init)
        self.assertIn("release-ready", init)
        self.assertTrue((self.output / "config/app/runtime/app/example1.php").exists())
        self.assertTrue((self.output / "config/app/workers/worker.php").is_file())
        self.assertFalse((self.output / "config/app/runtime/workers").exists())
        self.assertTrue((self.output / "config/www/assets/pages/dashboard/assets.json").exists())
        self.assertTrue((self.output / "linnms.service.d/linnms-sampler-1").exists())
        self.assertTrue((self.output / "config/app/settings/config_default.php").is_file())
        self.assertTrue((self.output / "config/app/settings/defaults.php").is_file())
        self.assertTrue((self.output / "config/app/settings/database/migrate.php").is_file())
        self.assertFalse((self.output / "config/app/settings/database.php").exists())

    def test_configuration_layout_is_preserved(self):
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)], check=True)
        init = (self.output / "linnms.init.d/50-linnms-public-init").read_text()
        self.assertNotIn('find "$APP_ROOT/config" -mindepth', init)
        self.assertIn("CONFIG_IMAGE=/opt/linnms/settings-seed", init)
        self.assertIn("# Persistent configuration", init)
        self.assertIn('seed_config_file "$config_file" "$config_source" || exit 1', init)
        for name in ("error.txt", "logs_velue.txt", "toats_message.txt"):
            source = self.stage / "config/app/settings" / name
            target = self.output / "config/app/settings" / name
            self.assertEqual(source.read_bytes(), target.read_bytes())
        self.assertFalse((self.output / "config/app/default").exists())
        self.assertFalse((self.output / "config/app/config").exists())
        for name in ("config/app/runtime/app/paths.php", "config/www/app/helpers/toast-message.php",
                     "config/app/runtime/src/helpers/log-message.php"):
            self.assertEqual((self.stage / name).read_bytes(), (self.output / name).read_bytes())

    def test_staging_init_without_shebang(self):
        init_path = self.stage / 'linnms.init.d/50-linnms-public-init'
        init_path.write_text(INIT.removeprefix('#!/usr/bin/with-contenv sh\n'))
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)], check=True)
        converted = (self.output / 'linnms.init.d/50-linnms-public-init').read_text()
        self.assertTrue(converted.startswith('#!/usr/bin/with-contenv sh\nset -eu\n'))
        self.assertEqual(converted.count('#!/usr/bin/with-contenv sh'), 1)
        self.assertNotIn('SOURCE_MOUNTS', converted)
        self.assertNotIn('staging', converted.lower())

    def test_auditor_enforces_selected_runtime_encoding(self):
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)],
                       check=True)
        context = self.root / "context"
        (context / "bundle").mkdir(parents=True)
        (context / "pq/loader").mkdir(parents=True)
        (context / "pq/loader/nx.c").write_text("/* build only */")
        (context / "pq/loader/build.h").write_text("#define NX_BUILD_DATA 1")
        (context / "Dockerfile").write_text("FROM scratch")
        import shutil
        shutil.copytree(self.output, context / "bundle", dirs_exist_ok=True)
        for scope in ("backend", "src", "vendor"):
            for php in (context / "bundle/config/app/runtime" / scope).rglob("*.php"):
                php.write_bytes(b"LNC1" + bytes(64))
        subprocess.run([sys.executable, str(VERIFY), str(context)], check=True)
        unprotected = context / "bundle/config/www/entrypoints/index.php"
        self.assertTrue(unprotected.read_bytes().startswith(b"<?php"))
        seed = context / "bundle/config/app/settings/service.php"
        self.assertTrue(seed.read_bytes().startswith(b"<?php"))
        protected = context / "bundle/config/app/runtime/vendor/driver/example1.php"
        protected.write_text("<?php echo 1;")
        result = subprocess.run([sys.executable, str(VERIFY), str(context)],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("E08", result.stderr + result.stdout)
        protected.write_bytes(b"LNC1" + bytes(64))
        unprotected.write_bytes(b"LNC1" + bytes(64))
        result = subprocess.run([sys.executable, str(VERIFY), str(context)],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("E08", result.stderr + result.stdout)
        unprotected.write_text("<?php echo 1;")
        for plain in (seed, context / "bundle/config/app/runtime/app/paths.php",
                      context / "bundle/config/app/workers/worker.php"):
            original = plain.read_bytes()
            self.assertTrue(original.startswith(b"<?php"))
            plain.write_bytes(b"LNC1" + bytes(64))
            result = subprocess.run([sys.executable, str(VERIFY), str(context)],
                                    capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("E08", result.stderr + result.stdout)
            plain.write_bytes(original)

if __name__ == "__main__":
    unittest.main()
