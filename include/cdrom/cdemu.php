<?php

//////////////////////////////////////
// Title: CDEmu
// Description: CD Image Decoder
//////////////////////////////////////
// Supported Image Formats
//   + CUE/BIN
//     + Multifile support
//   + ISO
//   + CDEMU Full Dump Index
//
// Supported Sector Types
//   + Audio
//   + Mode 0
//   + Mode 1
//   + Mode 2 (Formless)
//   + Mode 2 XA Form 1
//   + Mode 2 XA Form 2
//////////////////////////////////////

class CDEMU {
	const bin_sector_size = 2352;
	const iso_sector_size = 2048;
	
	private $lut = array(); // EDC/ECC LUT
	private $fh = 0; // File handle
	private $buffer = 0; // Sector buffer
	private $buffer_limit = true; // Limit buffer
	private $CD = 0; // CD variable tracking
	private $track = 0; // Current track
	private $sector = 0; // Current sector
	private $sect_list = array(); // Accessed sector list
	private $sect_list_en = false; // Accessed sector list control
	
	function __construct() {
		$this->lut_init(); // Init EDC/ECC LUTs
  	}
	
	// Initilize Emulated CD
	private function init() {
		$this->buffer = array(); // Null buffer
		$this->track = 1; // Current track
		$this->sector = 0; // Current sector
		
		// Init CD image information
		$this->CD = array();
		$this->CD['multifile'] = false; // Default to single file
		$this->CD['sector_count'] = 0; // Sector count
		$this->CD['track_count'] = 1; // Init track count to 1
		$this->CD['track'] = array(); // Track information
	}
	
	// Close file and clean-up internal variables
	public function eject() {
		if (is_resource ($this->fh))
			fclose ($this->fh);
		$this->fh = 0;
		$this->buffer = array();
		$this->CD = 0;
		$this->track = 0;
		$this->sector = 0;
		$this->sect_list = array(); // Clear sector access list
		$this->sect_list_en = false; // Disable sector access list
		$this->buffer_limit = true;
	}
	
	// Load CUE file
	public function load_cue ($cue_file) {
		if (!file_exists ($cue_file))
			return (false);
		$path = explode ("/", $cue_file);
		$cue_file = $path[count ($path) - 1];
		$path[count ($path) - 1] = '';
		if (($path = implode ('/', $path)) == '')
			$path = './';
		$cue = file ($path . $cue_file); // Load CUE
		$sector_count = 0;
		$disk = array();
		$track = array();
		foreach ($cue as $line) { // Process CUE into array
			$line = trim ($line);
			$e_line = explode (' ', $line);
			switch (strtolower ($e_line[0])) {
				case 'file':
					$type = strtolower ($e_line[count ($e_line) - 1]); // File type
					if ($type != "binary")
						return (false);
					if (isset ($file))
						$multifile = true;
					$file = trim (substr ($line, 5, strlen ($line) - (strlen ($type) + 6))); // Parse file from between FILE and TYPE
					if (($qc = substr ($file, 0, 1)) == '"' or $qc == "'")
						$file = substr ($file, 1, strlen ($file) - 2);
					if (!file_exists ($path . $file)) { // File not found
						$ff = false; // File found
						if (file_exists ($path . basename ($file))) // Try stripping any directories
							$ff = basename ($file);
						if ($ff === false)
							return (false);
						$file = $ff;
					}
					$sector_count += filesize ($path . $file) / self::bin_sector_size; // Sector count using file length
					break;
				case 'track':
					if (isset ($t_in)) { // New track
						if (!isset ($index))
							return (false);
						$track['index'] = $index;
						$disk[count ($disk) + 1] = $track; // Save track
						$track = array();
						$index = array();
					}
					if (!isset ($file))
						return (false);
					$t_in = true; // Inside track
					$track['file'] = $file;
					$track['file_format'] = CDEMU_FILE_BIN;
					$track_format = strtolower ($e_line[2]); // Save Mode
					if ($track_format == 'audio')
						$track['track_format'] = CDEMU_TRACK_AUDIO; // Audio
					else if (substr ($track_format, 0, 4) == 'mode')
						$track['track_format'] = CDEMU_TRACK_DATA; // Data
					else
						return (false);
					break;
				case 'index':
					$index[(int)$e_line[1]] = $e_line[2]; // Save time into index
					break;
				/*
				case 'pregap':
					$track['pregap'] = $e_line[1];
					break;
				case 'postgap':
					$track['postgap'] = $e_line[1];
					break;
				*/
				default:
			}
		}
		$track['index'] = $index; 
		$disk[count ($disk) + 1] = $track; // Save track
		
		$this->init(); // Init
		$this->CD['multifile'] = isset ($multifile);
		$this->CD['sector_count'] = $sector_count;
		$this->CD['track_count'] = count ($disk);
		for ($i = 1; $i <= $this->CD['track_count']; $i++) { // Process each track
			$this->CD['track'][$i] = array(); // init track
			$this->CD['track'][$i]['file'] = $path . $disk[$i]['file']; // File
			$this->CD['track'][$i]['file_format'] = CDEMU_FILE_BIN;
			$this->CD['track'][$i]['format'] = $disk[$i]['track_format']; // Track format
			if ($this->CD['multifile']) { // Multi-file
				if ($i == 1)
					$this->CD['track'][$i]['lba'] = 0; // First track
				else
					$this->CD['track'][$i]['lba'] = $this->CD['track'][$i - 1]['lba'] + $this->CD['track'][$i - 1]['length']; // Length using last track
				$this->CD['track'][$i]['length'] = (filesize ($path . $disk[$i]['file']) / self::bin_sector_size); // Length using filesize
			} else { // Single File
				$this->CD['track'][$i]['lba'] = $this->msf2lba ($disk[$i]['index'][1]); // Start Sector
				if (isset ($disk[$i + 1]['index'][1]))
					$this->CD['track'][$i]['length'] = $this->msf2lba ($disk[$i + 1]['index'][1]) - $this->msf2lba ($disk[$i]['index'][1]); // Length using next track
				else
					$this->CD['track'][$i]['length'] = (filesize ($path . $disk[$i]['file']) / self::bin_sector_size) - $this->msf2lba ($disk[$i]['index'][1]); // Length using filesize
			}
			if (count ($disk[$i]['index']) > 0) {
				foreach ($disk[$i]['index'] as $k => $v)
					$this->CD['track'][$i]['index'][$k] = $this->CD['track'][$i]['lba'] + $this->msf2lba ($v);
			}
		}
		$this->seek (0);
		return (true);
	}
	
	// Load BIN file
	// Returns true on success, false on error
	public function load_bin ($file, $data_only = false) {
		if (!file_exists ($file))
			return (false);
		$audio = false;
		$fp = fopen ($file, 'r');
		$header = fread ($fp, 12);
		fclose ($fp);
		if ($header != "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00") { // Data track detection
			if ($data_only)
				return (false);
			if ($this->load_iso ($file)) // Attempt to load as ISO
				return (true);
			$audio = true; // Assume audio track
		}
		if (!is_array ($this->CD) or !is_array ($this->CD['track'])) // Init check
			$this->init();
		else {
			$this->CD['multifile'] = true; // Set multi-file
			$this->CD['track_count']++;	// Increment track count
		}
		$this->CD['track'][$this->CD['track_count']] = array();
		$this->CD['track'][$this->CD['track_count']]['file'] = $file;
		$this->CD['track'][$this->CD['track_count']]['file_format'] = CDEMU_FILE_BIN;
		$this->CD['track'][$this->CD['track_count']]['format'] = $audio ? CDEMU_TRACK_AUDIO : CDEMU_TRACK_DATA;
		$this->CD['track'][$this->CD['track_count']]['lba'] = $this->CD['track_count'] == 1 ? 0 : $this->CD['track'][$this->CD['track_count'] - 1]['lba'] + $this->CD['track'][$this->CD['track_count'] - 1]['length'];
		$this->CD['track'][$this->CD['track_count']]['length'] = filesize ($file) / self::bin_sector_size;
		$this->CD['track'][$this->CD['track_count']]['index'][0] = $this->CD['track'][$this->CD['track_count']]['lba'];
		$this->CD['sector_count'] += $this->CD['track'][$this->CD['track_count']]['length']; // Use filesize to determine sectors
		return (true);
	}
	
	// Load ISO file
	// Returns true on success, false on error
	public function load_iso ($file) {
		if (!file_exists ($file))
			return (false);
		$fp = fopen ($file, 'r');
		fseek ($fp, 2048 * 16 + 1);
		$header = fread ($fp, 5);
		fclose ($fp);
		if ($header !== "CD001") {
			if ($this->load_bin ($file, true)) // Attempt to load as data BIN
				return (true);
			return (false);
		}
		$this->init(); // Init
		$this->CD['track'][$this->CD['track_count']] = array();
		$this->CD['track'][$this->CD['track_count']]['file'] = $file;
		$this->CD['track'][$this->CD['track_count']]['file_format'] = CDEMU_FILE_ISO;
		$this->CD['track'][$this->CD['track_count']]['format'] = CDEMU_TRACK_DATA;
		$this->CD['track'][$this->CD['track_count']]['lba'] = $this->CD['track_count'] == 1 ? 0 : $this->CD['track'][$this->CD['track_count'] - 1]['lba'] + $this->CD['track'][$this->CD['track_count'] - 1]['length'];
		$this->CD['track'][$this->CD['track_count']]['length'] = filesize ($file) / self::iso_sector_size;
		$this->CD['track'][$this->CD['track_count']]['index'][0] = $this->CD['track'][$this->CD['track_count']]['lba'];
		$this->CD['sector_count'] += $this->CD['track'][$this->CD['track_count']]['length'];
		return (true);
	}
	
	// Load index.cdemu file
	public function load_cdemu_index ($file) {
		if (!file_exists ($file))
			return (false);
		$fp = fopen ($file, 'r');
		$header = fread ($fp, 5);
		fclose ($fp);
		if ($header != "CDEMU") // Header check
			return (false);
		$this->init(); // Init
		$path = explode ("/", $file);
		$file = $path[count ($path) - 1];
		$path[count ($path) - 1] = '';
		if (($path = implode ('/', $path)) == '')
			$path = './';
		$r_i = file ($path . $file); // Text index
		$multifile = 0;
		foreach ($r_i as $k_i => $i) {
			$i = explode (' ', trim ($i));
			switch (strtolower ($i[0])) {
				case 'length':
					$this->CD['sector_count'] = (int)trim ($i[1]);
					break;
				case 'track':
					if (count ($i) < 4)
						return (false);
					$track = (int)trim ($i[1]); // Track
					if ($track > $this->CD['track_count'])
						$this->CD['track_count'] = $track;
					$this->CD['track'][$track]['file_format'] = CDEMU_FILE_CDEMU;
					$this->CD['track'][$track]['format'] = strtolower (trim ($i[2])) == 'audio' ? CDEMU_TRACK_AUDIO : CDEMU_TRACK_DATA;
					$this->CD['track'][$track]['lba'] = (int)trim ($i[3]);
					for ($j = 3; $j < count ($i); $j++)
						$this->CD['track'][$track]['index'][] = (int)trim ($i[$j]);
					if ($track == 1 and $this->CD['track'][$track]['format'] == CDEMU_TRACK_DATA) {
						$this->CD['track'][$track]['index'][1] = $this->CD['track'][$track]['index'][0];
						unset ($this->CD['track'][$track]['index'][0]);
					}
					break;
				case 'f2edc':
					$this->CD['cdemu']['form2edc'] = (bool)trim ($i[1]);
					break;
				case 'cdmode':
					if (count ($i) == 2) {
						$data = file_get_contents ($path . "CDMODE" . trim ($i[1]) . ".bin");
						$lba = (int)trim ($i[1]);
						for ($j = 0; $j < strlen ($data); $j++)
							$this->CD['cdemu']['sector'][$lba + $j]['mode'] = $data[$j];
						break;
					}
					$mode = trim ($i[1]);
					$lba_start = trim ($i[2]);
					$lba_end = trim ($i[3]);
					for ($j = (int)$lba_start; $j <= (int)$lba_end; $j++)
						$this->CD['cdemu']['sector'][$j]['mode'] = $mode;
					break;
				case 'cdaddr':
					$data = file_get_contents ($path . "CDADDR" . trim ($i[1]) . ".bin");
					$lba = (int)trim ($i[1]);
					for ($j = 0; $j < strlen ($data); $j++)
						$this->CD['cdemu']['sector'][$lba + $j]['address'] = $data[$j];
					break;
				case 'cdxa':
					$data = file_get_contents ($path . "CDXA" . trim ($i[1]) . ".bin");
					$lba = (int)trim ($i[1]);
					for ($j = 0; $j < strlen ($data); $j += 4)
						$this->CD['cdemu']['sector'][$lba + ($j / 4)]['xa'] = $this->parse_xa (substr ($data, $j, 4));
					break;
				case 'cdedc':
					$data = file_get_contents ($path . "CDEDC" . trim ($i[1]) . ".bin");
					$lba = (int)trim ($i[1]);
					for ($j = 0; $j < strlen ($data); $j += 4)
						$this->CD['cdemu']['sector'][$lba + ($j / 4)]['edc'] = substr ($data, $j, 4);
					break;
				case 'cdecc':
					$data = file_get_contents ($path . "CDECC" . trim ($i[1]) . ".bin");
					$lba = (int)trim ($i[1]);
					for ($j = 0; $j < strlen ($data); $j += 276)
						$this->CD['cdemu']['sector'][$lba + ($j / 276)]['ecc'] = substr ($data, $j, 276);
					break;
				case 'cdsect':
					$data = file_get_contents ($path . "CDSECT" . trim ($i[1]) . ".bin");
					$lba = (int)trim ($i[1]);
					for ($j = 0; $j < strlen ($data); $j += 2352)
						$this->CD['cdemu']['sector'][$lba + ($j / 2352)]['sector'] = substr ($data, $j, 2352);
					break;
				case 'lba':
					if (!$this->CD['multifile'] and ++$multifile > 1)
						$this->CD['multifile'] = true;
					if (!isset ($i[2])) // Partial data
						$this->CD['cdemu']['lba'][(int)trim ($i[1])] = $path . "LBA" . trim ($i[1]) . ".bin";
					else { // Full audio
						$track = $this->get_track_by_sector ((int)trim ($i[1]));
						if (($format = strtolower (trim ($i[2]))) != "cdda") // CDDA check
							return (false);
						//$this->CD['cdemu']['lba'][(int)trim ($i[1])] = $path . "LBA" . trim ($i[1]) . ".cdda";
						$this->CD['track'][$track]['file_format'] = CDEMU_FILE_BIN;
						$this->CD['track'][$track]['file'] = $path . "LBA" . trim ($i[1]) . ".cdda";
					}
					break;
				default:
					break;
			}
		}
		for ($i = 1; $i <= $this->CD['track_count']; $i++) { // Process each track
			if ($i + 1 > $this->CD['track_count'])
				$this->CD['track'][$i]['length'] = $this->CD['sector_count'] - $this->CD['track'][$i]['lba']; // Length using image length
			else
				$this->CD['track'][$i]['length'] = $this->CD['track'][$i + 1]['lba'] - $this->CD['track'][$i]['lba']; // Length using next track
		}
		$this->seek (0);
		return (true);
	}
	
	// Seeks to position in sector or msf format
	// Returns true on success, false if end of disk
	public function seek ($pos) {
		if (!is_numeric ($pos))
			$pos = $this->msf2lba ($pos);
		if (!is_numeric ($pos) or $pos >= $this->CD['sector_count']) // Make sure we are inside our limits
			return (false); // EOD
		$this->sector = $pos; // Set current sector
		$this->track_detect(); // Detect track after seek
		return (true);
	}
	
	// Detect track and when multi-file close file
	private function track_detect() {
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
				$this->track = $t; // Save found/last track
			}
		}
	}
	
	// Read currect sector from image, optionally seek and/or limit processing to only return sector data
	public function &read ($seek = false) {
		$fail = false;
		if ($seek !== false and $seek != $this->sector and !$this->seek ($seek))
			return ($fail); // Seek failed
		if (!isset ($this->buffer[$this->sector])) { // Needed sector not in buffer
			if ($this->buffer_limit)
				$this->buffer = array(); // Clear buffer
			$seq = false;
			for ($i = $this->sector; $i < ($this->sector + 250) and $i < $this->CD['sector_count']; $i++) { // Load sectors into buffer
				if (isset ($this->buffer[$i]))
					continue;
				$track = $this->get_track_by_sector ($i);
				if ($this->CD['track'][$track]['file_format'] == CDEMU_FILE_CDEMU and isset ($this->CD['cdemu']['sector'][$i]['sector'])) {
					$this->buffer[$i] = $this->read_bin_sector ($this->CD['cdemu']['sector'][$i]['sector']);
					continue;
				}
				if (($sector_size = $this->file_seek ($i, $seq)) === false)
					return ($fail);
				$seq = true;
				$data = fread ($this->fh, $sector_size); // Read sector
				if ($this->CD['track'][$track]['file_format'] == CDEMU_FILE_CDEMU) {
					$this->buffer[$i] = $this->cdemu_gen_sector ($data, $i); // Generate sector from CDEMU data
					continue;
				}
				if (strlen ($data) < $sector_size)
					break;
				if ($this->CD['track'][$track]['file_format'] == CDEMU_FILE_BIN)
					$this->buffer[$i] = $this->read_bin_sector ($data); // Process BIN data into sector
				else if ($this->CD['track'][$track]['file_format'] == CDEMU_FILE_ISO)
					$this->buffer[$i] = $this->gen_sector_mode1 ($data, $i); // Generate Mode 1 sector from ISO data
				else
					break;
			}
		}
		if (isset ($this->buffer[$this->sector]) and $this->buffer[$this->sector] !== false) {
			$sector = &$this->buffer[$this->sector]; // Save sector
			if ($this->sect_list_en)
				$this->sect_list[$this->sector] = isset ($this->sect_list[$this->sector]) ? $this->sect_list[$this->sector] + 1 : 1; // Increment access list
			$this->sector++; // Increment sector	
			$this->track_detect(); // Detect track after sector change
			return ($sector); // return sector
		}
		return ($fail); // EOF
	}
	
	// Ensure proper file is open and we are at the correct position
	// Note: if $seq is set to true and no file change, skip seeking
	// Note: Returns size to be read from file for $sector, false on error
	private function file_seek ($sector, $seq = false) {
		$seek = true;
		$track = $this->get_track_by_sector ($sector);
		if ($this->CD['track'][$track]['file_format'] == CDEMU_FILE_BIN or $this->CD['track'][$track]['file_format'] == CDEMU_FILE_ISO) { // BIN / ISO
			$sector_size = $this->CD['track'][$track]['file_format'] == CDEMU_FILE_BIN ? self::bin_sector_size : self::iso_sector_size;
			if (is_resource ($this->fh)) {
				$m_fh = stream_get_meta_data ($this->fh);
				if ($m_fh['uri'] != $this->CD['track'][$track]['file'])
					fclose ($this->fh);
				else if ($seq)
					$seek = false;
			}
			if (!is_resource ($this->fh))
				$this->fh = fopen ($this->CD['track'][$track]['file'], 'r');
			if (!$seek)
				return ($sector_size);
			$pos = ($sector - $this->CD['track'][$track]['lba']) * $sector_size;
		} else if ($this->CD['track'][$track]['file_format'] == CDEMU_FILE_CDEMU) { // CDEMU full dump
			foreach (array_keys ($this->CD['cdemu']['lba']) as $s) {
				if ($s <= $sector)
					$lba = $s;
			}
			if (!isset ($lba))
				return (false);
			if (is_resource ($this->fh)) {
				$m_fh = stream_get_meta_data ($this->fh);
				if ($m_fh['uri'] != $this->CD['cdemu']['lba'][$lba])
					fclose ($this->fh);
				else if ($seq)
					$seek = false;
			}
			if (!is_resource ($this->fh))
				$this->fh = fopen ($this->CD['cdemu']['lba'][$lba], 'r');
			$sector_size = $this->cdemu_sector_size ($sector);
			if (!$seek)
				return ($sector_size);
			$pos = 0;
			if ($lba != $sector) {
				for ($i = $lba; $i < $sector; $i++)
					$pos += $this->cdemu_sector_size ($i);
			}
		} else
			return (false);
		if (fseek ($this->fh, $pos) != 0) // Seek to proper file location
			return (false);
		return ($sector_size);
	}
	
	// Find stored sector size from CDEMU full dump data
	private function cdemu_sector_size ($sector) {
		if (!isset ($this->CD['cdemu']['sector'][$sector]))
			return (2352); // Audio
		else if ($this->CD['cdemu']['sector'][$sector]['mode'] == 0)
			return (2336); // Mode 0
		else if ($this->CD['cdemu']['sector'][$sector]['mode'] == 1)
			return (2048); // Mode 1
		else if (isset ($this->CD['cdemu']['sector'][$sector]['xa'])) {
			if ($this->CD['cdemu']['sector'][$sector]['xa']['submode']['form'] == 1)
				return (2048); // Mode 2 XA Form 1
			else
				return (2324); // Mode 2 XA Form 2
		} else
			return (2336); // Mode 2 (Formless)
	}
	 
	// Generate sectors from CDEMU full dump data
	private function &cdemu_gen_sector (&$data, $lba) {
		if (!isset ($this->CD['cdemu']['sector'][$lba])) {
			$data = $this->gen_sector_audio ($data); // Audio
			return ($data);
		}
		$mode = isset ($this->CD['cdemu']['sector'][$lba]['mode']) ? $this->CD['cdemu']['sector'][$lba]['mode'] : false;
		$addr = isset ($this->CD['cdemu']['sector'][$lba]['address']) ? $this->CD['cdemu']['sector'][$lba]['address'] : false;
		$xa = isset ($this->CD['cdemu']['sector'][$lba]['xa']) ? $this->CD['cdemu']['sector'][$lba]['xa'] : false;
		if (isset ($this->CD['cdemu']['form2edc']) and !$this->CD['cdemu']['form2edc'] and isset ($this->CD['cdemu']['sector'][$lba]['xa']) and $this->CD['cdemu']['sector'][$lba]['xa']['submode']['form'] == 2)
			$edc = "\x00\x00\x00\x00";
		else
			$edc = isset ($this->CD['cdemu']['sector'][$lba]['edc']) ? $this->CD['cdemu']['sector'][$lba]['edc'] : false;
		$ecc = isset ($this->CD['cdemu']['sector'][$lba]['ecc']) ? $this->CD['cdemu']['sector'][$lba]['ecc'] : false;
		if ($this->CD['cdemu']['sector'][$lba]['mode'] == 0)
			$data = $this->gen_sector_mode0 ($addr === false ? $lba : $addr, $mode); // Mode 0
		else if ($this->CD['cdemu']['sector'][$lba]['mode'] == 1)
			$data = $this->gen_sector_mode1 ($data, $addr === false ? $lba : $addr, $mode, $edc, $ecc); // Mode 1
		else if ($this->CD['cdemu']['sector'][$lba]['mode'] > 1) {
			if (isset ($this->CD['cdemu']['sector'][$lba]['xa']))
				$data = $this->gen_sector_mode2xa ($data, $addr === false ? $lba : $addr, $xa, $mode, $edc, $ecc); // Mode 2 XA Form 1/2
			else
				$data = $this->gen_sector_mode2 ($data, $addr === false ? $lba : $addr, $mode); // Mode 2 (Formless)
		}
		return ($data);
	}
	
	// Generate Audio sector
	private function &gen_sector_audio (&$data) {
		if (strlen ($data) > 2352)
			$data = substr ($data, 0, 2352); // Clip
		$s = array();
		$s['sector'] = str_pad ($data, 2352, "\x00");
		$s['type'] = CDEMU_SECT_AUDIO;
		$s['data'] = $s['sector'];
		return ($s);
	}
	
	// Generate Mode 0 sector
	private function &gen_sector_mode0 ($lba, $mode = false) {
		$s = array();
		$s['sync'] = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";
		$s['address'] = $this->lba2header ($lba);
		$s['mode'] = $mode === false ? 0 : $mode;
		$s['type'] = CDEMU_SECT_MODE0;
		$s['data'] = str_repeat ("\x00", 2336);
		$s['sector'] = $s['sync'] . $this->lba2header ($lba) . chr ($s['mode']) . $s['data'];
		return ($s);
	}
	
	// Generate Mode 1 sector
	private function &gen_sector_mode1 (&$data, $lba, $mode = false, $edc = false, $ecc = false) {
		if (strlen ($data) > 2048)
			$data = substr ($data, 0, 2048); // Clip
		$s = array();
		$s['sync'] = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";
		$s['address'] = $this->lba2header ($lba);
		$s['mode'] = $mode === false ? 1 : $mode;
		$s['type'] = CDEMU_SECT_MODE1;
		$s['data'] = str_pad ($data, 2048, "\x00");
		$s['sector'] = $s['sync'] . $this->lba2header ($lba) . chr ($s['mode']) . $s['data'];
		if ($edc === false) {
			$s['edc'] = $this->edc_compute ($s['sector'], 0, 2064);
			$s['reserved'] = "\x00\x00\x00\x00\x00\x00\x00\x00";
			$s['sector'] .= $s['edc'] . $s['reserved'];
			if ($ecc === false)
				$s['ecc'] = $this->ecc_compute ($s['sector']);
			else {
				$s['ecc'] = $ecc;
				$s['error']['ecc'] = $this->ecc_compute ($s['sector']);
			}
			$s['sector'] .= $s['ecc'];
		} else {
			$s['edc'] = $edc;
			$s['error']['edc'] = $this->edc_compute ($s['sector'], 0, 2064);
			$s['reserved'] = "\x00\x00\x00\x00\x00\x00\x00\x00";
			if ($ecc === false) {
				$s['sector'] .= $s['edc'] . $s['reserved'];
				$s['ecc'] = $this->ecc_compute ($s['sector']);
				$s['sector'] .= $s['ecc'];
			} else {
				$s['ecc'] = $ecc;
				$s['error']['ecc'] = $this->ecc_compute ($s['sector'] . $s['error']['edc'] . $s['reserved']);
				$s['sector'] .= $s['edc'] . $s['reserved'] . $s['ecc'];
			}
		}
		return ($s);
	}
	
	// Generate Mode 2 sector
	private function &gen_sector_mode2 (&$data, $lba, $mode = false) {
		if (strlen ($data) > 2336)
			$data = substr ($data, 0, 2336); // Clip
		$s = array();
		$s['sync'] = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";
		$s['address'] = $this->lba2header ($lba);
		$s['mode'] = $mode === false ? 0 : $mode;
		$s['type'] = CDEMU_SECT_MODE2;
		$s['data'] = str_pad ($data, 2336, "\x00");
		$s['sector'] = $s['sync'] . $this->lba2header ($lba) . chr ($s['mode']) . $s['data'];
		return ($s);
	}
	
	// Generate Mode 2 XA Form 1/2 sector
	private function &gen_sector_mode2xa (&$data, $lba, $xa, $mode = false, $edc = false, $ecc = false) {
		if ($this->CD['cdemu']['sector'][$lba]['xa']['submode']['form'] == 1) {
			if (strlen ($data) > 2048)
				$data = substr ($data, 0, 2048); // Clip
		} else {
			if (strlen ($data) > 2324)
				$data = substr ($data, 0, 2324); // Clip
		}
		$s = array();
		$s['sync'] = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";
		$s['address'] = $this->lba2header ($lba);
		$s['mode'] = $mode === false ? 2 : $mode;
		$s['subheader'] = $xa['raw'] . $xa['raw'];
		$s['xa'] = $xa;
		$s['type'] = $this->CD['cdemu']['sector'][$lba]['xa']['submode']['form'] == 1 ? CDEMU_SECT_MODE2FORM1 : CDEMU_SECT_MODE2FORM2;
		$s['data'] = str_pad ($data, $this->CD['cdemu']['sector'][$lba]['xa']['submode']['form'] == 1 ? 2048 : 2324, "\x00");
		$s['sector'] = $s['sync'] . $s['address'] . chr ($s['mode']) . $s['subheader'] . $s['data'];
		if ($xa['submode']['form'] == 1) { // Form 1
			if ($edc === false) {
				$s['edc'] = $this->edc_compute ($s['sector'], 16, 2056);
				$s['sector'] .= $s['edc'];
				if ($ecc === false)
					$s['ecc'] = $this->ecc_compute ($s['sector']);
				else {
					$s['ecc'] = $ecc;
					$s['error']['ecc'] = $this->ecc_compute ($s['sector']);
				}
				$s['sector'] .= $s['ecc'];
			} else {
				$s['edc'] = $edc;
				$s['error']['edc'] = $this->edc_compute ($s['sector'], 16, 2056);
				if ($ecc === false) {
					$s['sector'] .= $s['edc'];
					$s['ecc'] = $this->ecc_compute ($s['sector']);
					$s['sector'] .= $s['ecc'];
				} else {
					$s['ecc'] = $ecc;
					$s['error']['ecc'] = $this->ecc_compute ($s['sector'] . $s['error']['edc']);
					$s['sector'] .= $s['edc'] . $s['ecc'];
				}
			}
		} else { // Form 2
			if ($edc === false) {
				$s['edc'] = $this->edc_compute ($s['sector'], 16, 2332);
				$s['sector'] .= $s['edc'];
			} else {
				$s['edc'] = $edc;
				$s['error']['edc'] = $this->edc_compute ($s['sector'], 16, 2332);
				$s['sector'] .= $s['edc'];
			}
		}
		return ($s);
	}
	
	// Parse BIN sector into usable format
	private function &read_bin_sector (&$sector) {
		$s = array();
		$s['sector'] = $sector; // Raw sector
		
		// Audio
		if ($this->CD['track'][$this->track]['format'] == CDEMU_TRACK_AUDIO or substr ($sector, 0, 12) != "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00") {
			$s['type'] = CDEMU_SECT_AUDIO;
			$s['data'] = $sector; // 2352b
			return ($s);
		}
		
		// Data Track Header
		$s['sync'] = substr ($sector, 0, 12);
		$s['address'] = substr ($sector, 12, 3);
		$s['mode'] = ord (substr ($sector, 15, 1));
	
		// Mode 0
		if ($s['mode'] == 0) {
			$s['type'] = CDEMU_SECT_MODE0;
			$s['data'] = substr ($sector, 16, 2336); // 2336b (Zeroes)
			return ($s);
		}
	
		// Mode 1
		$m1_edc = $this->edc_compute ($sector, 0, 2064); // Header + Data
		if (substr ($sector, 2064, 4) == $m1_edc) { // EDC Mode 1 Test
			$s['type'] = CDEMU_SECT_MODE1;
			$s['data'] = substr ($sector, 16, 2048); // 2048b
			$s['edc'] = substr ($sector, 2064, 4);
			$s['reserved'] = substr ($sector, 2068, 8);
			$s['ecc'] = substr ($sector, 2076, 276);
			if (($ecc = $this->ecc_compute ($sector)) != $s['ecc'])
				$s['error']['ecc'] = $ecc;
			return ($s);
		}
		
		// Mode 2 XA
		if (substr ($sector, 16, 4) == substr ($sector, 20, 4)) { // Detect XA extension
			$s['subheader'] = substr ($sector, 16, 8); // Subheader - XA data repeated 
			$s['xa'] = $this->parse_xa (substr ($sector, 16, 4)); // XA Data
			
			// XA Form 1
			$m2xa1_edc = $this->edc_compute ($sector, 16, 2056); // XA Subheader + Data
			if (substr ($sector, 2072, 4) == $m2xa1_edc) { // Mode 2 XA Form 1 EDC Test
				$s['type'] = CDEMU_SECT_MODE2FORM1;
				$s['data'] = substr ($sector, 24, 2048); // 2048b
				$s['edc'] = substr ($sector, 2072, 4);
				$s['ecc'] = substr ($sector, 2076, 276);
				if (($ecc = $this->ecc_compute ($sector)) != $s['ecc'])
					$s['error']['ecc'] = $ecc;
				return ($s);
			}

			// XA Form 2
			$m2xa2_edc = $this->edc_compute ($sector, 16, 2332); // XA Subheader + Data
			if (substr ($sector, 2348, 4) == $m2xa2_edc) { // Mode 2 XA Form 2 EDC Test
				$s['type'] = CDEMU_SECT_MODE2FORM2;
				$s['data'] = substr ($sector, 24, 2324); // 2324b
				$s['edc'] = substr ($sector, 2348, 4);
				return ($s);
			}
			
			// Trust XA Form
			if ($s['xa']['submode']['form'] == 1) { // Mode 2 XA Form 1
				$s['type'] = CDEMU_SECT_MODE2FORM1;
				$s['data'] = substr ($sector, 24, 2048); // 2048b
				$s['edc'] = substr ($sector, 2072, 4);
				if ($m2xa1_edc != $s['edc'])
					$s['error']['edc'] = $m2xa1_edc;
				$s['ecc'] = substr ($sector, 2076, 276);
				if (($ecc = $this->ecc_compute ($sector)) != $s['ecc'])
					$s['error']['ecc'] = $ecc;
				return ($s);
			} else if ($s['xa']['submode']['form'] == 2) { // Mode 2 XA Form 2
				$s['type'] = CDEMU_SECT_MODE2FORM2;
				$s['data'] = substr ($sector, 24, 2324); // 2324b
				$s['edc'] = substr ($sector, 2348, 4);
				if ($m2xa2_edc != $s['edc'])
					$s['error']['edc'] = $m2xa2_edc;
				return ($s);
			}
		}
		
		// Trust mode for formless mode 2 detection
		if ($s['mode'] == 2) {
			$s['type'] = CDEMU_SECT_MODE2;
			$s['data'] = substr ($sector, 16, 2336); // 2336b
			return ($s);
		}
		
		// Default to mode 1
		$s['type'] = CDEMU_SECT_MODE1;
		$s['data'] = substr ($sector, 16, 2048); // 2048b
		$s['edc'] = substr ($sector, 2064, 4);
		$s['error']['edc'] = $m1_edc;
		$s['reserved'] = substr ($sector, 2068, 8);
		$s['ecc'] = substr ($sector, 2076, 276);
		if (($ecc = $this->ecc_compute ($sector)) != $s['ecc'])
			$s['error']['ecc'] = $ecc;
		return ($s);
	}
	
	private function &parse_xa ($data) {
		$xa = array();
		$xa['raw'] = $data;
		$xa['file_number'] = ord ($data[0]); // File Number
		$xa['channel_number'] = ord ($data[1]); // Channel Number
		$xa['submode']['eof'] = (ord ($data[2]) >> 7) & 0x01; // End of File
		$xa['submode']['realtime'] = (ord ($data[2]) >> 6) & 0x01; // Real Time
		$xa['submode']['form'] = ((ord ($data[2]) >> 5) & 0x01) + 1; // XA Data Form
		$xa['submode']['trigger'] = (ord ($data[2]) >> 4) & 0x01; // Trigger Interrupt
		$xa['submode']['data'] = (ord ($data[2]) >> 3) & 0x01; // Format Data
		$xa['submode']['audio'] = (ord ($data[2]) >> 2) & 0x01; // Format Audio
		$xa['submode']['video'] = (ord ($data[2]) >> 1) & 0x01; // Format Video
		$xa['submode']['eor'] = (ord ($data[2]) >> 0) & 0x01; // End of Record
		if ($xa['submode']['audio']) { // Format Audio
			$xa['codeinfo']['reserved'] = (ord ($data[3]) >> 7) & 0x01; // Reserved
			$xa['codeinfo']['emphasis'] = (ord ($data[3]) >> 6) & 0x01; // Emphasis
			$xa['codeinfo']['bps'] = (((ord ($data[3]) >> 4) & 0x07) + 1) * 4; // Bits Per Sample
			$xa['codeinfo']['frequency'] = (ord ($data[3]) >> 2) & 0x07; // Frequency
			$xa['codeinfo']['frequency'] = $xa['codeinfo']['frequency'] ? 18900 : 37800;
			$xa['codeinfo']['channels'] = ((ord ($data[3]) >> 0) & 0x07) + 1; // Channel Layout
		} else //if ($xa['submode']['video'] or $xa['submode']['data']) // Format Video / Data / Other
			$xa['codeinfo'] = ord ($data[3]);
		return ($xa);
	}
	
	// Optionally analyzes image for reconstruction data, and optionally hashes image and tracks 
	public function analyze_image ($analyze, $hash_algos, $cb_progress) {
		$hash_algos = cdemu_hash_validate ($hash_algos);
		if ($hash_algos === false and !$analyze)
			return (false);
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		$r_info = array();
		if ($hash_algos !== false) {
			foreach ($hash_algos as $algo)
				$r_info['hash']['full'][$algo] = hash_init ($algo); // Init full hash
		}
		if (!$this->set_track (1))
			return (false); // Track change error (Image ended)
		$s_len = $this->get_length (true);
		for ($s_cur = 0; $s_cur < $s_len; $s_cur++) {
			$t_cur = $this->get_track(); // Get current track
			if ($hash_algos !== false and !isset ($r_info['hash']['track'][$t_cur])) {
				foreach ($hash_algos as $algo)
					$r_info['hash']['track'][$t_cur][$algo] = hash_init ($algo); // Init track hash
			}
			$sector = $this->read (false, $analyze ? false : true);
			if ($analyze) {
				if (isset ($sector['mode']))
					$r_info['analytics']['mode'][$s_cur] = $sector['mode']; // Mode
				if (isset ($sector['address']) and $this->lba2header ($s_cur) != $sector['address'])
					$r_info['analytics']['address'][$s_cur] = $sector['address']; // Address
				if (isset ($sector['xa']))
					$r_info['analytics']['xa'][$s_cur] = $sector['xa']['raw']; // XA
				if (isset ($sector['error']) and (isset ($sector['edc']) or isset ($sector['ecc']))) {
					if (isset ($sector['error']['edc']))
						$r_info['analytics']['edc'][$s_cur] = $sector['edc']; // EDC
					if (isset ($sector['xa']) and $sector['xa']['submode']['form'] == 2) {
						$r_info['analytics']['form2_edc_log'][] = $s_cur; // Track sectors for optional error removal
						if ($sector['edc'] != "\x00\x00\x00\x00") // Detect optional XA Form 2 EDC
							$r_info['analytics']['form2edc'] = true;
						else if (!isset ($r_info['analytics']['form2edc']))
							$r_info['analytics']['form2edc'] = false;
					}
					if (isset ($sector['error']['ecc']))
						$r_info['analytics']['ecc'][$s_cur] = $sector['ecc']; // ECC
				}
			}
			if ($hash_algos !== false) {
				foreach ($r_info['hash']['full'] as $hash)
					hash_update ($hash, $sector['sector']);
				foreach ($r_info['hash']['track'][$t_cur] as $hash)
					hash_update ($hash, $sector['sector']);
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $s_len, $s_cur + 1);
		}
		if (isset ($r_info['analytics']['form2edc']) and !$r_info['analytics']['form2edc']) { // Remove all optional EDC values
			foreach ($r_info['analytics']['form2_edc_log'] as $lba) {
				if ($r_info['analytics']['edc'][$lba] == "\x00\x00\x00\x00")
					unset ($r_info['analytics']['edc'][$lba]);
			}
			unset ($r_info['analytics']['form2_edc_log']);
		}
		if ($hash_algos !== false) {
			foreach ($r_info['hash']['full'] as $algo => $hash)
				$r_info['hash']['full'][$algo] = hash_final ($hash, false);
			foreach ($r_info['hash']['track'] as $t_cur => $h) {
				foreach ($h as $algo => $hash)
					$r_info['hash']['track'][$t_cur][$algo] = hash_final ($hash, false);
			}
		}
		return ($r_info);
	}
	
	// Save sectors to $file
	// Note: $length is in sectors, not filesize
	// Note: If $file is false only hash will be computed
	public function save_sector ($file, $sector, $length = 1, $raw = true, $hash_algos = false, $cb_progress = false) {
		$hash_algos = cdemu_hash_validate ($hash_algos);
		if ($file === false and $hash_algos === false) // Nothing to do
			return (false);
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		if ($hash_algos !== false) {
			$hashes = array();
			foreach ($hash_algos as $algo)
				$hashes[$algo] = hash_init ($algo); // Init hash
		}
		if ($sector >= $this->CD['sector_count'])
			return (false);
		if ($file !== false and ($fh = fopen ($file, 'w')) === false)
			return (false); // File error: could not open file for writing
		for ($pos = 0; $pos < $length; $pos++) {
			if ($sector + $pos >= $this->CD['sector_count'] or ($data = $this->read ($sector + $pos, $raw)) === false)
				continue; // Sector read error
			$data = $raw ? $data['sector'] : $data['data'];
			if ($file !== false and fwrite ($fh, $data) === false)
				return (false); // File error: out of space
			if ($hash_algos !== false) {
				foreach ($hashes as $hash)
					hash_update ($hash, $data);
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $length, $pos + 1);
		}
		if ($file !== false) {
			fflush ($fh);
			fclose ($fh);
		}
		if ($hash_algos !== false) {
			foreach ($hashes as $algo => $hash)
				$hashes[$algo] = hash_final ($hash, false);
			return ($hashes);
		}
		return (true);	
	}
	
	// Save track to file, with optional hashing support
	// Note: If $file is false only hash will be computed
	public function save_track ($file, $track = false, $hash_algos = false, $cb_progress = false) {
		if ($track !== false and !$this->set_track ($track))
			return (false); // Track change error (Image ended)
		$s_start = $this->get_track_start (true);
		$s_len = $this->get_track_length (true);
		return ($this->save_sector ($file, $s_start, $s_len, true, $hash_algos, $cb_progress));
	}
	
	// Save image as ISO
	public function save_iso ($file, $hash_algos = false, $cb_progress = false) {
		$hash_algos = cdemu_hash_validate ($hash_algos);
		if ($hash_algos === false and !$analyze)
			return (false);
		$fh = fopen ($file, 'w');
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		$r_info = array();
		if ($hash_algos !== false) {
			foreach ($hash_algos as $algo)
				$r_info['hash'][$algo] = hash_init ($algo); // Init full hash
		}
		if (!$this->seek (0))
			return (false); // Seek error
		$s_len = $this->get_length (true);
		$r_info['length'] = 0;
		for ($s_cur = 0; $s_cur < $s_len; $s_cur++) {
			$sector = $this->read();
			if (strlen ($sector['data']) > 2048)
				$sector['data'] = substr ($sector['data'], 0, 2048); // Trim
			if (!is_resource ($fh) or fwrite ($fh, $sector['data']) === false)
				return (false); // Write failure
			$r_info['length'] += strlen ($sector['data']);
			if ($hash_algos !== false) {
				foreach ($r_info['hash'] as $hash)
					hash_update ($hash, $sector['data']);
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $s_len, $s_cur + 1);
		}
		if ($hash_algos !== false) {
			foreach ($r_info['hash'] as $algo => $hash)
				$r_info['hash'][$algo] = hash_final ($hash, false);
		}
		if (is_resource ($fh))
			fclose ($fh);
		return ($r_info);
	}
	
	// Populate LUTs for EDC and ECC
	// Ported From: ECM Tools (Neill Corlett)
	private function lut_init () {
		for ($i = 0; $i < 256; $i++) {
			$edc = $i;
			for ($j = 0; $j < 8; $j++)
				$edc = (($edc >> 1) ^ ($edc & 1 ? 0xD8018001 : 0)) & 0xFFFFFFFF;
			$this->lut['edc'][$i] = $edc;
			$f = ($i << 1) ^ ($i & 0x80 ? 0x11D : 0x00);
			$this->lut['ecc']['f'][$i] = $f;
			$this->lut['ecc']['b'][$i ^ $f] = $i;
		}
	}
	
	// Compute Error Detection Code
	// Ported From: ECM Tools (Neill Corlett)
	private function &edc_compute (&$sector, $start, $length) {
		$edc = 0;
		for ($i = $start; $i < ($start + $length); $i++)
			$edc = (($edc >> 8) ^ $this->lut['edc'][($edc ^ ord ($sector[$i])) & 0xFF]) & 0xFFFFFFFF;
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
	// Ported From: ECM Tools (Neill Corlett)
	// Note: Modifies $sector
	private function circ_compute (&$sector, $major_count, $minor_count, $major_mult, $minor_inc, $pos) {
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
				$ecc_a = $this->lut['ecc']['f'][$ecc_a];
			}
			$ecc_a = $this->lut['ecc']['b'][$this->lut['ecc']['f'][$ecc_a] ^ $ecc_b];
			$sector[2076 + $pos + $major] = chr ($ecc_a);
			$sector[2076 + $pos + $major + $major_count] = chr ($ecc_a ^ $ecc_b);
		}
	}
	
	// Header to ATime
	public function header2msf ($h) {
		$minutes = bin2hex (substr ($h, 0, 1));
		$seconds = bin2hex (substr ($h, 1, 1)) - 2;
		if ($seconds < 0) {
			$minutes--;
			$seconds = 60 + $seconds;
		}
		$minutes = str_pad ($minutes, 2, "0", STR_PAD_LEFT);
		$seconds = str_pad ($seconds, 2, "0", STR_PAD_LEFT);
		$frames = str_pad (bin2hex (substr ($h, 2, 1)), 2, "0", STR_PAD_LEFT);
		return ("$minutes:$seconds:$frames");
	}
	
	// Header to Logical Block Address
	public function header2lba ($h) {
		$minutes = bin2hex (substr ($h, 0, 1));
		$seconds = bin2hex (substr ($h, 1, 1)) - 2;
		if ($seconds < 0) {
			$minutes--;
			$seconds = 60 + $seconds;
		}
		$frames = bin2hex (substr ($h, 2, 1));
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
		if ($seconds > 59) {
			$seconds -= 60;
			$minutes++;
		}
		$minutes = str_pad ($minutes, 2, "0", STR_PAD_LEFT);
		$seconds = str_pad ($seconds, 2, "0", STR_PAD_LEFT);
		$frames = str_pad ($time[2], 2, "0", STR_PAD_LEFT);
		return (hex2bin ($minutes) . hex2bin ($seconds) . hex2bin ($frames));
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
		if ($seconds > 59) {
			$seconds -= 60;
			$minutes++;
		}
		while ($minutes > 99)
			$minutes -= 99;
		$minutes = str_pad ($minutes, 2, "0", STR_PAD_LEFT);
		$seconds = str_pad ($seconds, 2, "0", STR_PAD_LEFT);
		$frames = str_pad ($frames, 2, "0", STR_PAD_LEFT);
		return (hex2bin ($minutes) . hex2bin ($seconds) . hex2bin ($frames));
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
				return ($t);
		}
		return (false);
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
	
	// Unaccessed sector areas, optionally set limits
	public function get_sector_unaccessed_list ($sector_start = false, $sector_end = false) {
		$sectors = array();
		$access = $this->sect_list;
		if ($sector_start === false)
			$sector_start = 0;
		if ($sector_end === false)
			$sector_end = $this->get_length (true) - 1;
		$gap = false;
		for ($i = $sector_start; $i <= $sector_end; $i++) {
			if ($gap === false and !isset ($access[$i]))
				$sectors[($gap = $i)] = 1;
			else if ($gap !== false and !isset ($access[$i]))
				$sectors[$gap]++;
			else
				$gap = false;
		}
		return ($sectors);
	}
	
	// Limit sector buffer ram
	public function enable_buffer_limit() {
		$this->buffer_limit = true;
	}
	
	// Unlimit sector buffer ram
	public function disable_buffer_limit() {
		$this->buffer_limit = false;
	}
	
	// Enable accessed sector list
	public function enable_sector_access_list() {
		$this->sect_list_en = true;
	}
	
	// Disable accessed sector list
	public function disable_sector_access_list() {
		$this->sect_list_en = false;
	}
	
	// Clear accessed sector list
	public function clear_sector_access_list ($sector = false) {
		if ($sector === false)
			$this->sect_list = array();
		else if (isset ($this->sect_list[$sector]))
			unset ($this->sect_list[$sector]);
		else
			return (false);
		return (true);
	}
	
	// Current sector
	public function get_sector() { 
		return ($this->sector);
	}
	
	// Sector count
	public function get_sector_count() { 
		return ($this->CD['sector_count']);
	}
	
	// Get internal image layout
	public function get_layout() {
		return ($this->CD);
	}
	
	// Set internal image layout
	public function set_layout ($layout) {
		$this->CD = $layout;
		return (true);
	}
}

?>