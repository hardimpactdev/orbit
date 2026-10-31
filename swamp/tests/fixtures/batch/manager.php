#!/usr/bin/env php
<?php
file_put_contents(__LOG__, basename($argv[0]).' '.implode(' ',array_slice($argv,1))."\n", FILE_APPEND);
$name = basename($argv[0]);
function chooseVersion($range) {
    $choices = explode(' || ', $range);
    $preferred = file_exists('prefer-version') ? trim(file_get_contents('prefer-version')) : null;
    return in_array($preferred, $choices, true) ? $preferred : $choices[0];
}
if ($name === 'npm' && $argv[1] === 'pack') {
    $version = substr($argv[2], strlen('expo@'));
    if (file_exists('fail-pack') && trim(file_get_contents('fail-pack')) === $version) { fwrite(STDERR, 'registry unavailable'); exit(70); }
    $archives = json_decode('__EXPO_TARS__', true);
    $i = array_search('--pack-destination', $argv);
    $filename = 'expo-'.$version.'.tgz';
    copy($archives[$version], $argv[$i+1].'/'.$filename);
    echo json_encode([['filename'=>$filename]]); exit;
}
if ($name === 'npm' && $argv[1] === 'view') {
    if (file_exists('fail-view') && trim(file_get_contents('fail-view')) === $argv[2]) { fwrite(STDERR, 'registry unavailable'); exit(70); }
    $registry = file_exists('registry-fixture.json') ? json_decode(file_get_contents('registry-fixture.json'), true) : [];
    if (isset($registry[$argv[2]])) {
        $row = $registry[$argv[2]];
        if (isset($row['_stderr'])) { fwrite(STDERR, $row['_stderr']); exit(1); }
        echo json_encode($row); exit(isset($row['error']) ? 1 : 0);
    }
    echo json_encode(['versions'=>['1.5.0','2.0.0'], 'time'=>['1.5.0'=>'2020-01-01T00:00:00Z','2.0.0'=>'2020-01-01T00:00:00Z']]); exit;
}
if ($name === 'composer' && $argv[1] === 'update') {
    $m = json_decode(file_get_contents('composer.json'), true);
    $old = json_decode(file_get_contents('composer.lock'), true);
    $versions = array_column(array_merge($old['packages'], $old['packages-dev']), 'version', 'name');
    $result = ['packages'=>[], 'packages-dev'=>[]];
    foreach (['require'=>'packages','require-dev'=>'packages-dev'] as $section=>$bucket) {
        foreach ($m[$section] ?? [] as $n=>$v) {
            $v = $versions[$n];
            foreach ($argv as $i=>$arg) if ($arg === '--with' && str_starts_with($argv[$i+1], $n.':')) $v = chooseVersion(explode(':',$argv[$i+1])[1]);
            $result[$bucket][] = ['name'=>$n,'version'=>$v];
        }
    }
    file_put_contents('composer.lock', json_encode($result)); exit;
}
$bun = $name === 'bun' || ($name === 'vp' && file_exists('bun.lock'));
$lockfile = $bun ? 'bun.lock' : 'package-lock.json';
if ($argv[1] === 'install') {
    $m = json_decode(file_get_contents('package.json'), true);
    $old = json_decode(file_get_contents($lockfile), true);
    $sync = in_array($bun ? '--lockfile-only' : '--package-lock-only', $argv);
    $sections = array_intersect_key($m, array_flip(['dependencies','devDependencies','optionalDependencies']));
    $rows = $old['packages'];
    if ($bun) $old['workspaces'][''] = array_merge($old['workspaces'][''] ?? [], $sections);
    else $rows[''] = $sections;
    foreach ($sections as $deps) foreach ($deps as $n=>$v) {
        if (str_starts_with($v, 'workspace:')) continue;
        $key = $bun ? $n : 'node_modules/'.$n;
        $previous = $bun ? substr($rows[$key][0], strlen($n)+1) : $rows[$key]['version'];
        $version = preg_match('/^[0-9]+[.][0-9]+[.][0-9]+$/', $v) || str_contains($v,' || ') ? ltrim(chooseVersion($v), '^~v=') : $previous;
        if ($sync && preg_match('/[~^<>*xX]/', $v)) $version = $previous;
        if ($sync && file_exists('move-version') && trim(file_get_contents('move-version')) !== 'nested') $version = '9.0.0';
        $rows[$key] = $bun ? [$n.'@'.$version, '', (object)[]] : ['version'=>$version];
    }
    if ($sync && file_exists('move-version') && trim(file_get_contents('move-version')) === 'nested') $rows['local/one'][0] = 'one@0.9.1';
    $old['packages'] = $rows;
    file_put_contents($lockfile, json_encode($old)); exit;
}
if ($argv[1] === 'audit' || ($argv[1] === 'pm' && $argv[2] === 'audit')) {
    if ($name === 'composer') {
        $lock = json_decode(file_get_contents('composer.lock'), true);
        $v = array_column(array_merge($lock['packages'],$lock['packages-dev']), 'version','name')['fixture/one'] ?? '';
        echo json_encode(['advisories'=>$v === '1.0.0' ? ['fixture/one'=>[['advisoryId'=>'fixture-advisory','link'=>'https://example.test/advisory','title'=>'fixture','affectedVersions'=>'<2']]] : []]);
    } else {
        $lock = json_decode(file_get_contents($lockfile), true);
        $v = $bun ? substr($lock['packages']['one'][0], 4) : $lock['packages']['node_modules/one']['version'];
        $row = ['id'=>'fixture-advisory','source'=>'fixture-advisory','url'=>'https://example.test/advisory','title'=>'fixture','range'=>'<2','vulnerable_versions'=>'<2'];
        echo json_encode($bun ? ($v === '1.0.0' ? ['one'=>[$row]] : (object)[]) : ['vulnerabilities'=>$v === '1.0.0' ? ['one'=>['via'=>[$row]]] : (object)[]]);
    }
    exit;
}
fwrite(STDERR, 'unexpected command'); exit(64);
