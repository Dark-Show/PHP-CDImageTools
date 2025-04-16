<?php

// File Formats
const CDEMU_FILE_BIN = 0; // 2352b sector
const CDEMU_FILE_ISO = 1; // 2048b sector
const CDEMU_FILE_CDEMU = 2; // Variable sector

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
const ISO9660_FILE_XA = 1; // XA-Interleaved or XA-Mode2
const ISO9660_FILE_CDDA = 2; // Link to CDDA Track

// ISO9660 Filesystem Map
const ISO9660_MAP_SYSTEM_USE = 0; // System Use
const ISO9660_MAP_VOLUME_DESCRIPTOR = 1; // Volume Descriptor
const ISO9660_MAP_PATH_TABLE = 2; // Path Table
const ISO9660_MAP_DIRECTORY_RECORD = 3; // Directory Record

?>