"""Real scratch removal; frozen Rust is not invoked or changed."""
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
SOURCE=re.search(r'`([\s\S]*)`;',Path(__file__).with_name('source.ts').read_text()).group(1)
class Removal(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='ai-source-')
        self.base=Path(self.tmp.name);self.root=self.base/'orbit';self.root.mkdir()
        for name in ['ai-router','proxycli','unrelated']:
            (self.root/name).mkdir();(self.root/name/'collector.py').write_text('source')
        self.source=SOURCE.replace("ROOT=pathlib.Path('/var/lib/orbit')",'ROOT=pathlib.Path('+repr(str(self.root))+')')
    def tearDown(self):self.tmp.cleanup()
    def invoke(self,value):
        result=subprocess.run(['/usr/bin/python3','-I','-c',self.source],input=json.dumps(value),capture_output=True,text=True,timeout=3,check=True)
        self.assertEqual(result.stderr,'');return json.loads(result.stdout)
    def test_removes_only_selected_source_and_repeats(self):
        for retired,name in [(False,'ai-router'),(True,'proxycli')]:
            self.assertEqual(self.invoke({'retired':retired}),{'removed':True})
            self.assertFalse((self.root/name).exists())
            self.assertEqual(self.invoke({'retired':retired}),{'removed':False})
            self.assertTrue((self.root/'unrelated/collector.py').exists())
    def test_refuses_target_symlink_and_regular_file(self):
        target=self.root/'ai-router';target.rename(self.base/'saved')
        target.symlink_to(self.root/'unrelated')
        self.assertEqual(self.invoke({'retired':False}),{'error_code':'ai-router.source_unsafe'})
        self.assertTrue((self.root/'unrelated/collector.py').exists())
        target.unlink();target.write_text('foreign')
        self.assertEqual(self.invoke({'retired':False}),{'error_code':'ai-router.source_unsafe'})
        self.assertEqual(target.read_text(),'foreign')
    def test_refuses_symlinked_ancestor(self):
        self.root.rename(self.base/'saved');self.root.symlink_to(self.base/'saved')
        self.assertEqual(self.invoke({'retired':False}),{'error_code':'ai-router.source_unsafe'})
        self.assertTrue((self.base/'saved/ai-router/collector.py').exists())
    def test_does_not_follow_descendant_links(self):
        (self.root/'ai-router/link').symlink_to(self.root/'unrelated')
        self.assertEqual(self.invoke({'retired':False}),{'removed':True})
        self.assertTrue((self.root/'unrelated/collector.py').exists())
    def test_absent_root_is_idempotent(self):
        self.root.rename(self.base/'saved')
        self.assertEqual(self.invoke({'retired':False}),{'removed':False})
    def test_raw_program_rejects_unbounded_selectors(self):
        for value in [{},{'retired':1},{'retired':False,'path':'/foreign'}]:
            self.assertEqual(self.invoke(value),{'error_code':'ai-router.source_unsafe'})
        self.assertTrue((self.root/'ai-router/collector.py').exists())
if __name__=='__main__':unittest.main()
