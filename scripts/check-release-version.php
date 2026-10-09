<?php
function read_version($path, $pattern) {
    $contents = file_get_contents($path);
    if ($contents === false || preg_match($pattern, $contents, $matches) !== 1) {
        fwrite(STDERR, "Could not read version from {$path}.\n");
        exit(1);
    }
    return trim($matches[1]);
}
$plugin_version = read_version('cook-app.php', '/^\s*\*\s*Version:\s*([^\r\n]+)$/m');
$stable_tag = read_version('README.md', '/^- Stable tag:\s*([^\r\n]+)$/m');
if ($plugin_version !== $stable_tag) {
    fwrite(STDERR, "Version mismatch: cook-app.php={$plugin_version}, README.md Stable tag={$stable_tag}. Update both before releasing.\n");
    exit(2);
}
$release_tag = getenv('RELEASE_TAG');
if ($release_tag !== false && $release_tag !== '') {
    $release_version = preg_replace('/^v/', '', $release_tag);
    if ($plugin_version !== $release_version) {
        fwrite(STDERR, "Version mismatch: release tag={$release_tag}, cook-app.php={$plugin_version}, README.md Stable tag={$stable_tag}. Update both files to match the release.\n");
        exit(2);
    }
}
echo "Release versions match: {$plugin_version}\n";
