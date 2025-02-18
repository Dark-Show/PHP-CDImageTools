<?php

// Errors
const CDEMU_RET_ERROR = false;

// File Formats
const CDEMU_FILE_BIN = 0; // 2352b sector
const CDEMU_FILE_ISO = 1; // 2048b sector

// Track Types
const CDEMU_TRACK_AUDIO = 0; // Audio
const CDEMU_TRACK_DATA = 1; // Data

// Sector Types
const CDEMU_SECT_AUDIO = 0; // Audio
const CDEMU_SECT_MODE0 = 1; // Mode 0 (2336b: Zeros)
const CDEMU_SECT_MODE1 = 2; // Mode 1 (2048b)
const CDEMU_SECT_MODE2 = 3; // Mode 2 (2336b: Formless)
const CDEMU_SECT_MODE2FORM1 = 4; // Mode 2 XA Form 1 (2048b)
const CDEMU_SECT_MODE2FORM2 = 5; // Mode 2 XA Form 2 (2324b)

// ISO9660 File Types
const ISO9660_FILE = 0; // Regular File
const ISO9660_FILE_XA = 1; // Includes Mode 2 Sectors
const ISO9660_FILE_CDDA = 2; // Link to CDDA Track

?>