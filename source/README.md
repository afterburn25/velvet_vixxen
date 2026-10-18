# Source archive

The complete repository-safe 0.3.0 application source is stored in `velvet-vixxen-0.3.0-repo-source.tar.gz` while the browsable-tree import is completed.

Extract it from the repository root with:

```bash
mkdir -p build/source-0.3.0
tar -xzf source/velvet-vixxen-0.3.0-repo-source.tar.gz -C build/source-0.3.0
cd build/source-0.3.0
bash scripts/test.sh
```

The archive excludes production credentials, runtime state, private media, generated screenshots, and generated Canva exports. `scripts/generate_demo_assets.php` creates safe demonstration artwork and media for tests.

This archive is a transition mechanism, not a substitute for the planned fully browsable source-tree import tracked by issue #1.
