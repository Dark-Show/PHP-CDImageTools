<?php

// Errors
const CDEMU_RET_ERR_READ = -1; // Image read (disk read errors, unexpected end of image)
const CDEMU_RET_ERR_FILE = -2; // File issue (Read: File not found, Write: disk full, no permissions)
const CDEMU_RET_ERR_CUE  = -3; // Unsupported CUE file

// File formats
const CDEMU_FILE_BIN = 0; // 2352b sector
const CDEMU_FILE_ISO = 1; // 2048b sector

// Track types
const CDEMU_TRACK_AUDIO = 0; // Audio
const CDEMU_TRACK_DATA = 1; // Data
?>