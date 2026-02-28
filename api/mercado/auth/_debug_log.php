<?php
// Debug logger - logs ALL auth requests to a file
// Debug logger disabled — was consuming php://input and breaking POST body parsing
// To re-enable, cache the body in a global: $GLOBALS['_raw_body'] = file_get_contents('php://input');
