<?php
$dir = '/tmp/screenshots/';
if (!is_dir($dir)) mkdir($dir, 0777, true);

$count = 0;
if (!empty($_FILES['files'])) {
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        if ($_FILES['files']['error'][$i] === 0) {
            $ext = strtolower(pathinfo($_FILES['files']['name'][$i], PATHINFO_EXTENSION));
            $name = date('Ymd_His') . '_' . $i . '.' . $ext;
            move_uploaded_file($tmp, $dir . $name);
            $count++;
        }
    }
}
header('Location: upload-form.php?ok=' . $count);
