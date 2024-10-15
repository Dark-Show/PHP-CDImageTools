<?php

//////////////////////////////////////
// Title: CDEmu
// Description: CD Image Decoder
//////////////////////////////////////
// Supported Functionality
//   + CUE/BIN format
//     + Multifile support
//   + ISO format
//     + Regenerate Mode 1 sector
//   + EDC/ECC generation
//   + LBA/ATIME/TRACK seeking
//   + Sector types:
//     + Mode 0 (2336b: Zeros)
//     + Mode 1 (2048b)
//     + Mode 2 (2336b)
//     + Mode 2 XA Form 1 (2048b)
//     + Mode 2 XA Form 2 (2324b)
//////////////////////////////////////

class CDEMU {
	const bin_sector_size = 2352;
	const iso_sector_size = 2048;
	
	private $fh = 0; // File handle
	private $buffer = 0; // Sector buffer
	private $CD = 0; // CD variable tracking
	private $track = 0; // Current track
	private $sector = 0; // Current sector
	private $sect_list = array(); // Accessed sector list
	private $lut_edc = array(); // EDC LUT
	private $lut_ecc_b = array(); // ECC LUT
	private $lut_ecc_f = array(); // ECC LUT
	
	function __construct() {
		$this->lut_init(); // Init EDC/ECC LUTs
  	}
	
	public function load_cue ($cue_file) {
		$path = explode ("/", $cue_file);
		$path[count ($path) - 1] = '';
		if (($path = implode ('/', $path)) == '')
			$path = './';
		if (!is_file ($path . $cue_file))
			return (false);
		$this->init(); // Init
		$cue = file ($path . $cue_file); // Load Cue
		$disk  = array();
		$track = array();
		$file = false;
		foreach ($cue as $line) { // Process each line
			$e_line = explode (' ', trim ($line));
			switch (strtolower ($e_line[0])) {
				case 'file':
					$type = strtolower ($e_line[count ($e_line) - 1]); // File type
					if ($file !== false) // if we already have a file
						$this->CD['multifile'] = true; // Multifile CD
					$file = trim (substr (trim ($line), 5, strlen ($line) - (strlen ($type) + 6))); // Store file from between FILE and TYPE
					if (($qc = substr ($file, 0, 1)) == '"' or $qc == "'")
						$file = substr ($file, 1, strlen ($file) - 2);
					if (is_file ($path . $file) and $type == "binary")
						$this->CD['sector_count'] += filesize ($path . $file) / self::bin_sector_size; // Use file length to determine sector size
					break;
				case 'track':
					if (isset ($ntrack) and isset ($track)) { // New track check (Save)
						$track['index'] = $index; // Save index
						$disk[$ntrack] = $track;  // Save track
						unset ($ntrack);
						$track = array(); // Init new track
						$index = array(); // Init new track index
					}
					$track['file']   = $file; // Save file
					$track['format'] = $type; // Save type
					$ntrack = (int)$e_line[1];   // Store track number
					$track['mode'] = $e_line[2]; // Save Mode
					break;
				case 'index':
					$index[(int)$e_line[1]] = $e_line[2];	// Save time into index
					break;
				case 'pregap':
					$track['pregap'] = $e_line[1];
					break;
				case 'postgap':
					$track['postgap'] = $e_line[1];
					break;
				default:
			}
		}
		$track['index'] = $index; 
		$disk[$ntrack] = $track; // Save last track
		$this->CD['track_count'] = count ($disk); // Save track count
		
		// Process cue into TOC
		$this->CD['track'] = array();
		for ($i = 1; $i <= count ($disk); $i++) { // Process each track
			$this->CD['track'][$i] = array(); // init track
			
			// Store Format
			if ($disk[$i]['mode'] == 'AUDIO')
				$this->CD['track'][$i]['format'] = CDEMU_FORMAT_AUDIO; // Raw Audio
			else if ($disk[$i]['mode'] == 'MODE1/2352' or $disk[$i]['mode'] == 'MODE2/2352')
				$this->CD['track'][$i]['format'] = CDEMU_FORMAT_DATA; // Raw Binary
			else
				continue;
			
			// Process index into start, length and pregap
			if ($this->CD['multifile']) { // Multifile
				if ($i == 1)
					$this->CD['track'][$i]['lba'] = 0; // First track
				else // The start positions depends on the last start position + last length
					$this->CD['track'][$i]['lba'] = $this->CD['track'][$i - 1]['lba'] + $this->CD['track'][$i - 1]['length']; // Calculate position from last track
				$this->CD['track'][$i]['length'] = (filesize ($path . $disk[$i]['file']) / self::bin_sector_size); // File size = length of track
			} else { // Single File
				$this->CD['track'][$i]['lba'] = $this->msf2lba($disk[$i]['index'][1]); // Start Sector
				if (isset ($disk[$i + 1]['index'][1])) // Do we have a track to get the end from
					$this->CD['track'][$i]['length'] = $this->msf2lba ($disk[$i + 1]['index'][1]) - $this->msf2lba ($disk[$i]['index'][1]); // Length
				else
					$this->CD['track'][$i]['length'] = (filesize ($path . $file) / self::bin_sector_size) - $this->msf2lba ($disk[$i]['index'][1]); // Length using filesize
			}
			if (isset ($disk[$i]['index'][0])) // Do we have a pregap
				$this->CD['track'][$i]['pregap'] = $this->msf2lba ($disk[$i]['index'][1]) - $this->msf2lba ($disk[$i]['index'][0]); // Pregap
			$this->CD['track'][$i]['file'] = $path . $disk[$i]['file']; // File
		}
		$this->seek (0); // Seek to CD beginning
		return (true);
	}
	
	// Load BIN file
	public function load_bin ($file, $audio = false) {
		// TODO: Auto detect if audio track
		if (!is_file ($file))
			return (false);
		if (!is_array ($this->CD) or !is_array ($this->CD['track'])) {
			$this->init();
			$this->CD['track'] = array();
		} else {
			$this->CD['multifile'] = true; // Already init, we are multifile.
			$this->CD['track_count']++;	// Increment track count
		}
		$this->CD['track'][$this->CD['track_count']] = array();
		$this->CD['track'][$this->CD['track_count']]['file'] = $file;
		if ($this->CD['track_count'] == 1)
			$this->CD['track'][$this->CD['track_count']]['lba'] = 0; // First track starts at sector 0
		else
			$this->CD['track'][$this->CD['track_count']]['lba'] = $this->CD['track'][$this->CD['track_count'] - 1]['lba'] + $this->CD['track'][$this->CD['track_count'] - 1]['length'];
		$this->CD['track'][$this->CD['track_count']]['length'] = filesize ($file) / self::bin_sector_size;
		$this->CD['track'][$this->CD['track_count']]['format'] = $audio ? CDEMU_FORMAT_AUDIO : CDEMU_FORMAT_DATA;
		$this->CD['sector_count'] += $this->CD['track'][$this->CD['track_count']]['length']; // Use filesize to determine sectors
		return (true);
	}
	
	// Load ISO file
	public function load_iso ($file) {
		if (!is_file ($file))
			return (false);
		$this->init(); // Init
		$this->CD['track'] = array();
		$this->CD['track'][$this->CD['track_count']] = array();
		$this->CD['track'][$this->CD['track_count']]['file'] = $file;
		if ($this->CD['track_count'] == 1)
			$this->CD['track'][$this->CD['track_count']]['lba'] = 0; // First track starts at sector 0
		else
			$this->CD['track'][$this->CD['track_count']]['lba'] = $this->CD['track'][$this->CD['track_count'] - 1]['lba'] + $this->CD['track'][$this->CD['track_count'] - 1]['length'];
		$this->CD['track'][$this->CD['track_count']]['length'] = filesize ($file) / self::iso_sector_size;
		$this->CD['track'][$this->CD['track_count']]['format'] = CDEMU_FORMAT_ISO;
		$this->CD['sector_count'] += $this->CD['track'][$this->CD['track_count']]['length'];
		return (true);
	}
	
	public function seek ($pos) {
		if (!is_numeric ($pos))
			$pos = $this->msf2lba ($pos);
		if (is_numeric ($pos) and $pos <= $this->CD['sector_count']) { // Make sure we are inside our limits
			if (is_resource ($this->fh)) // If we have a file open, close it so read() can do the file position seek
				fclose ($this->fh);
			$this->sector = $pos; // Set current sector
			return (true);
		}
		return (false); // EOF
	}
	
	// Public read function
	public function &read ($seek = false, $limit_processing = false) {
		$fail = false;
		if ($seek !== false and $seek != $this->sector and !$this->seek ($seek))
			return ($fail); // Seek failed
		
		// Choose sector size based on file format
		if ($this->CD['track'][$this->track]['format'] == CDEMU_FORMAT_AUDIO or $this->CD['track'][$this->track]['format'] == CDEMU_FORMAT_DATA)
			$sector_size = self::bin_sector_size;
		else if ($this->CD['track'][$this->track]['format'] == CDEMU_FORMAT_ISO)
			$sector_size = self::iso_sector_size;
		
		if ($this->sector < $this->CD['sector_count']) { // Same track check
			$start = $this->CD['track'][$this->track]['lba'];
			$end = ($start + $this->CD['track'][$this->track]['length']);
			if ($this->sector < $start or $this->sector >= $end) { // Outside Track?
				for ($t = 1; $t <= count ($this->CD['track']); $t++) { // Search tracks
					$start = $this->CD['track'][$t]['lba'];
					$end = $start + $this->CD['track'][$t]['length'];
					if ($this->sector >= $start and $this->sector < $end) // Inside track?
						break;
				}
				$this->track = $t; // Save found track. default to last track when EOD
				if (is_resource ($this->fh) and $this->CD['multifile']) // If track changed while multifile, reload proper image
					fclose ($this->fh); // Close file
			}
		}
		if (is_resource ($this->fh) && feof ($this->fh))
			fclose ($this->fh);
		if (!is_resource ($this->fh)) { // If no file is open, open file and seek
			$this->fh = fopen ($this->CD['track'][$this->track]['file'], 'r'); // Open track bin
			$this->buffer = array(); // Invalidate buffer
			$pos = $this->sector;
			if ($this->CD['multifile'])
				$pos -= $this->CD['track'][$this->track]['lba']; // Start of track minus current sector
			$pos = fseek ($this->fh, $pos * $sector_size);
		}
		if (!isset ($this->buffer[$this->sector])) { // Needed sector not in buffer
			$this->buffer = array(); // Clear buffer
			for ($i = $this->sector; $i < ($this->sector + 10); $i++) { // Load 10 sectors into buffer
				if ($i > $this->CD['sector_count'] or feof ($this->fh)) // if not end of disk/file (multifile)
					continue;
				$data = fread ($this->fh, $sector_size); // Read sector
				if ($this->CD['track'][$this->track]['format'] == CDEMU_FORMAT_AUDIO or $this->CD['track'][$this->track]['format'] == CDEMU_FORMAT_DATA) {
					if ($limit_processing)
						$this->buffer[$i] = array ('sector' => $data); // Forward raw bin/cue type sector
					else
						$this->buffer[$i] = $this->read_bin_sector ($data); // Process bin/cue type sector
				} else if ($this->CD['track'][$this->track]['format'] == CDEMU_FORMAT_ISO)
					$this->buffer[$i] = $this->read_iso_sector ($data, $i); // Process iso type sector
			}
		}
		
		// If we have our sector in buffer, return and increment
		// Note: If we overflow past EOF we will return false on the next read
		if (isset ($this->buffer[$this->sector])) {
			$sector = $this->buffer[$this->sector]; // Save sector
			$this->sect_list[$this->sector] = isset ($this->sect_list[$this->sector]) ? $this->sect_list[$this->sector]++ : 1; // Increment access list
			$this->sector++; // Increment sector		   
			return ($sector); // return sector
		}
		return ($fail); // EOF
	}
	
	public function eject() {
		if (is_resource ($this->fh))
			fclose ($this->fh);
		$this->fh = 0;
		$this->buffer = 0;
		$this->CD = 0;
		$this->track = 0;
		$this->sector = 0;
		$this->sect_list = array();
	}
	
	// Initilize Emulated CD
	private function init() {
		$this->buffer = 0; // Null buffer
		$this->track = 1; // Current track
		$this->sector = 0; // Current sector
		
		// Init CD image information
		$this->CD = array();
		$this->CD['multifile'] = false; // Default to single file
		$this->CD['sector_count'] = 0; // Sector count
		$this->CD['track_count'] = 1; // Init track count to 1
		$this->CD['track'] = 0; // Table of contents
	}
	
	private function &read_bin_sector (&$sector) {
		$s = array();
		$s['sector'] = $sector; // Save raw sector
		
		if ((isset ($this->CD['track'][$this->track]['format']) and $this->CD['track'][$this->track]['format'] == 0) or substr ($sector, 0, 12) != "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00") { // Audio check
			$s['data'] = $sector; // 2352b
			return ($s);
		}
		
		// Data Track Header
		//   Sync	  - 12b
		//   Address  - 3b
		//   Mode	  - 1b
		$s['sync'] = substr ($sector, 0, 12);
		$s['address'] = $this->header2msf (substr ($sector, 12, 3));
		$s['mode'] = ord (substr ($sector, 15, 1));
	
		// Mode 0:
		//   Zeroes - 2336b
		if ($s['mode'] == 0) {
			$s['data'] = substr ($sector, 16, 2336); // 2336b
			return ($s);
		}
	
		// Mode 1:
		//   User Data - 2048b
		//   EDC	   - 4b
		//   Unused	   - 8b
		//   ECC	   - 276b
		$m1_edc = $this->edc_compute ($sector, 0, 2064); // Header + Data
		if (substr ($sector, 2064, 4) == $m1_edc) { // EDC Mode 1 Test
			$s['data'] = substr ($sector, 16, 2048); // 2048b
			$s['edc'] = substr ($sector, 2064, 4);
			$s['edc_gen'] = $m1_edc;
			$s['reserved'] = substr ($sector, 2068, 8);
			$s['ecc'] = substr ($sector, 2076, 276);
			$s['ecc_gen'] = $this->ecc_compute ($sector);
			return ($s);
		}
		
		if (substr ($sector, 16, 4) == substr ($sector, 20, 4)) { // Detect XA extension
			$s['subheader'] = substr ($sector, 16, 8); // Subheader - 4byte x2
			$xa = array();
			$xa['file_number'] = ord (substr ($sector, 16, 1)); // File Number
			$xa['channel_number'] = ord (substr ($sector, 17, 1)); // Channel Number

			// Submode
			$xa['submode'] = array();
			$xa['submode']['eof'] = (ord (substr ($sector, 18, 1)) >> 7) & 0x01; // End of File
			$xa['submode']['realtime'] = (ord (substr ($sector, 18, 1)) >> 6) & 0x01; // Real Time
			$xa['submode']['form'] = ((ord (substr ($sector, 18, 1)) >> 5) & 0x01) + 1; // XA Data Form
			$xa['submode']['trigger'] = (ord (substr ($sector, 18, 1)) >> 4) & 0x01; // Trigger Interrupt
			$xa['submode']['data'] = (ord (substr ($sector, 18, 1)) >> 3) & 0x01; // Format Data
			$xa['submode']['audio'] = (ord (substr ($sector, 18, 1)) >> 2) & 0x01; // Format Audio
			$xa['submode']['video'] = (ord (substr ($sector, 18, 1)) >> 1) & 0x01; // Format Video
			$xa['submode']['eor'] = (ord (substr ($sector, 18, 1)) >> 0) & 0x01; // End of Record

			// Coding information
			$xa['codeinfo'] = array(); // Format Coding Information
			if ($xa['submode']['audio']) { // Format Audio
				$xa['codeinfo']['reserved'] = (ord (substr ($sector, 19, 1)) >> 7) & 0x01; // Reserved
				$xa['codeinfo']['emphasis'] = (ord (substr ($sector, 19, 1)) >> 6) & 0x01; // Emphasis
				$xa['codeinfo']['bps'] = (((ord (substr ($sector, 19, 1)) >> 4) & 0x07) + 1) * 4; // Bits Per Sample
				$xa['codeinfo']['frequency'] = (ord (substr ($sector, 19, 1)) >> 2) & 0x07; // Frequency
				$xa['codeinfo']['frequency'] = $xa['codeinfo']['frequency'] ? 18900 : 37800;
				$xa['codeinfo']['channels'] = ((ord (substr ($sector, 19, 1)) >> 0) & 0x07) + 1; // Channel Layout
			} else //if ($xa['submode']['video'] or $xa['submode']['data']) // Format Video / Data / Other
				$xa['codeinfo'] = ord (substr ($sector, 19, 1));
			$s['xa'] = $xa;

			// XA Form 1:
			//   User Data - 2048b
			//   EDC	   - 4b
			//   ECC	   - 276b
			$m2xa1_edc = $this->edc_compute ($sector, 16, 2056); // XA Subheader + Data
			if (substr ($sector, 2072, 4) == $m2xa1_edc) { // Mode 2 XA Form 1 EDC Test
				$s['data'] = substr ($sector, 24, 2048); // 2048b
				$s['edc'] = substr ($sector, 2072, 4);
				$s['edc_gen'] = $m2xa1_edc;
				$s['ecc'] = substr ($sector, 2076, 276);
				$s['ecc_gen'] = $this->ecc_compute ($sector);
				return ($s);
			}

			// XA Form 2:
			//   User Data - 2324b
			//   EDC	   - 4b
			$m2xa2_edc = $this->edc_compute ($sector, 16, 2332); // XA Subheader + Data
			if (substr ($sector, 2348, 4) == $m2xa2_edc) { // Mode 2 XA Form 2 EDC Test
				$s['data'] = substr ($sector, 24, 2324); // 2324b
				$s['edc'] = substr ($sector, 2348, 4);
				$s['edc_gen'] = $m2xa2_edc;
				return ($s);
			}
			
			// Trust XA Form
			if ($s['xa']['submode']['form'] == 1) { // Mode 2 XA Form 1
				$s['data'] = substr ($sector, 24, 2048); // 2048b
				$s['edc'] = substr ($sector, 2072, 4);
				$s['edc_gen'] = $m2xa1_edc;
				$s['ecc'] = substr ($sector, 2076, 276);
				$s['ecc_gen'] = $this->ecc_compute ($sector);
				return ($s);
			} else if ($s['xa']['submode']['form'] == 2) { // Mode 2 XA Form 2
				$s['data'] = substr ($sector, 24, 2324); // 2324b
				$s['edc'] = substr ($sector, 2348, 4);
				$s['edc_gen'] = $m2xa2_edc;
				return ($s);
			}
		}
		
		// Trust header for mode 2 detection
		if ($s['mode'] == 2) { // Mode 2
			$s['data'] = substr ($sector, 16, 2336); // 2336b
			return ($s);
		}
		
		// Default to mode 1
		$s['data'] = substr ($sector, 16, 2048); // 2048b
		$s['edc'] = substr ($sector, 2064, 4);
		$s['edc_gen'] = $m1_edc;
		$s['reserved'] = substr ($sector, 2068, 8);
		$s['ecc'] = substr ($sector, 2076, 276);
		$s['ecc_gen'] = $this->ecc_compute ($sector);
		return ($s);
	}
	
	// Generate Mode 1 sector from ISO data
	private function &read_iso_sector (&$data, $lba) {
		$s = array();
		$s['sync'] = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";
		$s['address'] = $this->lba2msf ($lba);
		$s['mode'] = 1;
		$s['data'] = $data;
		$s['sector'] = $s['sync'] . $this->lba2header ($lba) . chr (1) . $data;
		$s['edc'] = $this->edc_compute ($s['sector'], 0, 2064);
		$s['reserved'] = "\x00\x00\x00\x00\x00\x00\x00\x00";
		$s['sector'] .= $s['edc'] . $s['reserved'];
		$s['ecc'] = $this->ecc_compute ($s['sector']);
		return ($s);
	}
	
	// Populate LUTs for EDC and ECC
	// Ported From: ECM Tools (Neill Corlett)
	private function lut_init () {
		for ($i = 0; $i < 256; $i++) {
			$edc = $i;
			for ($j = 0; $j < 8; $j++)
				$edc = (($edc >> 1) ^ ($edc & 1 ? 0xD8018001 : 0)) & 0xFFFFFFFF;
			$this->lut_edc[$i] = $edc;
			$f = ($i << 1) ^ ($i & 0x80 ? 0x11D : 0x00);
			$this->lut_ecc_f[$i] = $f;
			$this->lut_ecc_b[$i ^ $f] = $i;
		}
	}
	
	// Compute Error Detection Code
	// Ported From: ECM Tools (Neill Corlett)
	private function &edc_compute (&$sector, $start, $length) {
		$edc = 0;
		for ($i = $start; $i < ($start + $length); $i++)
			$edc = (($edc >> 8) ^ $this->lut_edc[($edc ^ ord ($sector[$i])) & 0xFF]) & 0xFFFFFFFF;
		$edc = pack ('V', $edc);
		return ($edc);
	}

	// Compute Error Correction Code
	// Ported From: ECM Tools (Neill Corlett)
	private function &ecc_compute ($sector) {
		$this->circ_compute ($sector, 86, 24, 2, 86, 0);
		$this->circ_compute ($sector, 52, 43, 86, 88, 172);
		$out = substr ($sector, 2076, 276);
		return ($out);
	}

	// Compute Cross Interleave Reed-Solomon Code
	// Note: Modifies $sector
	// Ported From: ECM Tools (Neill Corlett)
	private function &circ_compute (&$sector, $major_count, $minor_count, $major_mult, $minor_inc, $pos) {
		$size = $major_count * $minor_count;
		for ($major = 0; $major < $major_count; $major++) {
			$index = ($major >> 1) * $major_mult + ($major & 1);
			$ecc_a = 0;
			$ecc_b = 0;
			for ($minor = 0; $minor < $minor_count; $minor++) {
				if ($index < 4 and ord ($sector[0x0F]) == 2)
					$data = 0x00;
				else
					$data = ord ($sector[0x0C + $index]);
				$index += $minor_inc;
				if ($index >= $size)
					$index -= $size;
				$ecc_a = ($ecc_a ^ $data) & 0xFF;
				$ecc_b = ($ecc_b ^ $data) & 0xFF;
				$ecc_a = $this->lut_ecc_f[$ecc_a];
			}
			$ecc_a = $this->lut_ecc_b[$this->lut_ecc_f[$ecc_a] ^ $ecc_b];
			$sector[2076 + $pos + $major] = chr ($ecc_a);
			$sector[2076 + $pos + $major + $major_count] = chr ($ecc_a ^ $ecc_b);
		}
		return ($out);
	}
	
	// Header to ATime
	public function header2msf ($h) {
		$minutes = str_pad (ord (substr ($h, 0, 1)), 2, "0", STR_PAD_LEFT);
		$seconds = str_pad (ord ((int)substr ($h, 1, 1) - 2), 2, "0", STR_PAD_LEFT);
		$frames = str_pad (ord (substr ($h, 2, 1)), 2, "0", STR_PAD_LEFT);
		return ("$minutes:$seconds:$frames");
	}
	
	// Header to Logical Block Address
	public function header2lba ($h) {
		$minutes = ord (substr ($h, 0, 1));
		$seconds = ord ((int)substr ($h, 1, 1) - 2);
		$frames = ord (substr ($h, 2, 1));
		return (75 * ($minutes * 60 + $seconds) + $frames);
	}

	// Atime to Logical Block Address
	public function msf2lba ($t) {
		$time = explode (':', $t);
		$minutes = (int)$time[0];
		$seconds = (int)$time[1];
		$frames = (int)$time[2];
		return (75 * ($minutes * 60 + $seconds) + $frames);
	}
	
	// Atime to Header
	public function msf2header ($t) {
		$time = explode (':', $t);
		$minutes = (int)$time[0];
		$seconds = (int)$time[1] + 2;
		$frames = (int)$time[2];
		return (chr ($minutes) . chr ($seconds) . chr ($frames));
	}

	// Logical Block Address to ATime
	public function lba2msf ($s) {
		$seconds = intval ($s / 75);
		$frames = str_pad ($s - ($seconds * 75), 2, "0", STR_PAD_LEFT);
		$minutes = str_pad (intval ($seconds / 60), 2, "0", STR_PAD_LEFT);
		$seconds = str_pad ($seconds - ($minutes * 60), 2, "0", STR_PAD_LEFT);
		return ("$minutes:$seconds:$frames");
	}
	
	// Logical Block Address to Header
	public function lba2header ($s) {
		$seconds = intval ($s / 75);
		$frames = $s - ($seconds * 75);
		$minutes = intval ($seconds / 60);
		$seconds = ($seconds - ($minutes * 60)) + 2;
		return (chr ($minutes) . chr ($seconds)  . chr ($frames));
	}
	
	
	// Hash entire image
	public function hash_image ($hash_algos, $cb_progress = false) {
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		if (is_string ($hash_algos))
			$hash_algos = array ($hash_algos);
		foreach ($hash_algos as $algo) { // Verify hash format support
			foreach (hash_algos() as $sup_algo) {
				if ($sup_algo == $algo)
					continue 2;
			}
			return (false); // Error: Hash not found
		}
		$hashes = array();
		foreach ($hash_algos as $algo)
			$hashes[$algo] = hash_init ($algo); // Init hash
		
		if (!$this->set_track (1))
			return (false); // Track change error (Image ended)
		
		$s_len = $this->CD['sector_count'];
		for ($s_cur = 0; $s_cur < $s_len; $s_cur++) {
			$sector = $this->read (false, true);
			if (!isset ($sector['sector']))
				return (false); // Data read error
			if ($hash_algos !== false) {
				foreach ($hashes as $hash)
					hash_update ($hash, $sector['sector']);
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $s_len, $s_cur + 1);
		}
		
		foreach ($hashes as $algo => $hash)
			$hashes[$algo] = hash_final ($hash, false);
		return ($hashes);
	}

	// Change Track
	public function set_track ($track) {
		if (is_array ($this->CD['track']) and $track <= $this->CD['track_count'] and $this->seek ($this->CD['track'][$track]['lba']))
			$this->track = $track;
		else
			return (false);
		return (true);
	}
	
	// Get track number using sector
	public function get_track_by_sector ($sector) {
		for ($t = 1; $t <= count ($this->CD['track']); $t++) {
			if (!isset ($this->CD['track'][$t + 1]) or $this->CD['track'][$t + 1]['lba'] > $sector)
				break;
		}
		return ($t);
	}
	
	// Hash sector data using $hash_algos
	// Note: Multiple hash algos can be supplied by array ('sha1', 'crc32b');
	public function hash_sector ($hash_algos, $sector, $length = 1, $cb_progress = false) {
		return ($this->save_sector (false, $sector, $length, $hash_algos, $cb_progress));
	}
	
	// Save sectors to $file
	// Note: $length is in sectors, not filesize
	public function save_sector ($file, $sector, $length = 1, $hash_algos = false, $cb_progress = false) {
		if ($file === false and $hash_algos === false) // Nothing to do
			return (false);
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		
		if ($hash_algos !== false) {
			if (is_string ($hash_algos))
				$hash_algos = array ($hash_algos);
			foreach ($hash_algos as $algo) { // Verify hash format support
				foreach (hash_algos() as $sup_algo) {
					if ($sup_algo == $algo)
						continue 2;
				}
				return (false); // Error: Hash not found
			}
			$hashes = array();
			foreach ($hash_algos as $algo)
				$hashes[$algo] = hash_init ($algo); // Init hash
		}
		if ($file !== false and ($fp = fopen ($file, 'w')) === false)
			return (false); // File error: could not open file for writing
			
		if ($sector > $this->get_length (true))
			return (false);
		
		for ($pos = 0; $pos < $length; $pos++) {
			$data = $this->read ($sector + $pos, true);
			if (!isset ($data['sector']))
				return (false); // Data read error
			if ($file !== false and fwrite ($fp, $data['sector']) === false)
				return (false); // File error: out of space
			if ($hash_algos !== false) {
				foreach ($hashes as $hash)
					hash_update ($hash, $data['sector']);
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $length, $pos + 1);
		}
		if ($file !== false)
			fclose ($fp);
		if ($hash_algos !== false) {
			foreach ($hashes as $algo => $hash)
				$hashes[$algo] = hash_final ($hash, false);
			return ($hashes);
		}
		return (true);	
	}
	
	// Hash track data using $hash_algos
	// Note: Multiple hash algos can be supplied by array ('sha1', 'crc32b');
	public function hash_track ($hash_algos, $track = false, $cb_progress = false) {
		return ($this->save_track (false, $track, $hash_algos, $cb_progress));
	}
	
	// Save track to file, with optional hashing support
	// Note: If $file is false only hash will be computed
	public function save_track ($file, $track = false, $hash_algos = false, $cb_progress = false) {
		if ($file === false and $hash_algos === false) // Nothing to do
			return (false);
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		
		if ($hash_algos !== false) {
			if (is_string ($hash_algos))
				$hash_algos = array ($hash_algos);
			foreach ($hash_algos as $algo) { // Verify hash format support
				foreach (hash_algos() as $sup_algo) {
					if ($sup_algo == $algo)
						continue 2;
				}
				return (false); // Error: Hash not found
			}
			$hashes = array();
			foreach ($hash_algos as $algo)
				$hashes[$algo] = hash_init ($algo); // Init hash
		}
		
		if ($track !== false and !$this->set_track ($track))
			return (false); // Track change error (Image ended)
		
		if ($file !== false and ($fp = fopen ($file, 'w')) === false)
			return (false); // File error: could not open file for writing
			
		$s_len = $this->get_track_length (true);
		for ($s_cur = 0; $s_cur < $s_len; $s_cur++) {
			$sector = $this->read (false, true);
			if (!isset ($sector['sector']))
				return (false); // Data read error
			if ($file !== false and fwrite ($fp, $sector['sector']) === false)
				return (false); // File error: out of space
			if ($hash_algos !== false) {
				foreach ($hashes as $hash)
					hash_update ($hash, $sector['sector']);
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $s_len, $s_cur + 1);
		}
		if ($file !== false)
			fclose ($fp);
		if ($hash_algos !== false) {
			foreach ($hashes as $algo => $hash)
				$hashes[$algo] = hash_final ($hash, false);
			return ($hashes);
		}
		return (true);	
	}
	
	// Current track
	public function get_track() {
		return ($this->track);
	}
	
	// Track count
	public function get_track_count() {
		return ($this->CD['track_count']);
	}
	
	// Track start
	public function get_track_start ($sector = false) {
		if ($sector)
			return ($this->CD['track'][$this->track]['lba']);
		return ($this->lba2msf ($this->CD['track'][$this->track]['lba']));
	}
	
	// Track length
	public function get_track_length ($sector = false) {
		if ($sector)
			return ($this->CD['track'][$this->track]['length']);
		return ($this->lba2msf ($this->CD['track'][$this->track]['length']));
	}
	
	// Current time inside track
	public function get_track_time ($sector = false) {
		$cur = $this->sector - $this->CD['track'][$this->track]['lba']; // current - start
		if (!$sector)
			$cur = $this->lba2msf ($cur);
		return ($cur);
	}
	
	// Track type
	//   0 = Audio, 1 = Data
	public function get_track_type ($track = false) {
		if ($track === false)
			$track = $this->track;
		if (!isset ($this->CD['track'][$track]))
			return (false);
		return ($this->CD['track'][$track]['format']);
	}
	
	// CD length
	public function get_length ($sector = false) {
		if ($sector)
			return ($this->CD['sector_count']);
		return ($this->lba2msf ($this->CD['sector_count']));
	}
	
	// Current CD time
	public function get_time() {
		return ($this->lba2msf ($this->sector));
	}
	
	// Accessed sector list
	public function get_sector_access_list() {
		ksort ($this->sect_list, SORT_NUMERIC);
		return ($this->sect_list);
	}
	
	// Clear accessed sector list
	public function clear_sector_access_list() {	
		$this->sect_list = array();
	}
	
	// Current sector
	public function get_sector() { 
		return ($this->sector);
	}
	
	// Sector count
	public function get_sector_count() { 
		return ($this->CD['sector_count']);
	}
	
	// CD image layout
	public function get_layout() {
		return ($this->CD);
	}
}

?>