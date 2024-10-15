<?php

// Errors
const CDEMU_RET_SUCCESS = true; // Operation successful
const CDEMU_RET_ERR_FAIL = false; // Operation failed
const CDEMU_RET_ERR_READ = -1; // Image read (disk read errors, unexpected end of image)
const CDEMU_RET_ERR_FILE = -2; // File write (disk full, no permissions)

// Formats
const CDEMU_FORMAT_AUDIO = 0; // 2352b sector audio
const CDEMU_FORMAT_DATA = 1; // 2352b sector data
const CDEMU_FORMAT_ISO = 2; // 2048b sector data

?>