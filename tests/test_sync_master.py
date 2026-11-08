"""Regression tests for synchronization of the frontend only."""
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "master.py"


class MasterMirrorTests(unittest.TestCase):
    def test_exporter_copies_only_frontend(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "source"
            for folder in (
                "config/www/app", "config/www/entrypoints", "config/www/view",
                "config/app/runtime/backend", "config/app/config", "nginx",
                "linnms.init.d", "linnms.service.d", "docker",
            ):
                (source / folder).mkdir(parents=True, exist_ok=True)
            (source / "config/www/index.php").write_text("<?php")
            (source / "config/www/entrypoints/index.php").write_text("<?php")
            (source / "config/www/app/internal-api-client.php").write_text(
                "<?php function internal_api_request() {"
                "return 'http://127.0.0.1/internal-api.php'; }"
            )
            (source / "config/www/view/dashboard.php").write_text("frontend")
            (source / "config/app/runtime/backend/api.php").write_text("private")
            (source / "nginx/nginx.conf").write_text("private")
            (source / "docker/Dockerfile").write_text("private")
            (source / "config/www/vendor").mkdir()
            (source / "config/www/vendor/file.php").write_text("private")
            output = root / "out"
            subprocess.run([sys.executable, str(SCRIPT), str(source), str(output)], check=True)
            self.assertEqual(
                (output / "config/www/view/dashboard.php").read_text(), "frontend"
            )
            self.assertTrue(
                (output / "config/www/app/internal-api-client.php").is_file()
            )
            self.assertFalse((output / "config/www/vendor").exists())
            self.assertFalse((output / "config/app").exists())
            self.assertFalse((output / "nginx").exists())
            self.assertFalse((output / "docker").exists())
            self.assertFalse((output / "linnms.init.d").exists())
            self.assertFalse((output / "linnms.service.d").exists())


if __name__ == "__main__":
    unittest.main()
