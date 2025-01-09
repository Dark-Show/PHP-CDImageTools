<?php

// Errors
const CDEMU_RET_ERROR = false;

// File Formats
const CDEMU_FILE_BIN = 0; // 2352b sector
const CDEMU_FILE_ISO = 1; // 2048b sector

// Track Types
const CDEMU_TRACK_AUDIO = 0; // Audio
const CDEMU_TRACK_DATA = 1; // Data

// ISO9660 File Types
const ISO9660_FILE = 0; // Regular File
const ISO9660_FILE_XA = 1; // Includes Mode 2 Sectors
const ISO9660_FILE_CDDA = 2; // Link to CDDA Track

?>