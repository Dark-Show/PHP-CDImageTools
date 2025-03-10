<?php

//////////////////////////////////////
// Title: ISO9660
// Description: ISO9660 Filesystem Decoder
// Author: Greg Michalik
//////////////////////////////////////
// Supported Functionality
//   + System area
//     + Hashing
//   + Directory listing
//     + Metadata
//   + File retrieval
//     + Hashing
//   + Extensions:
//     + XA
//////////////////////////////////////

namespace CDEMU;
class ISO9660 {
	private $o_cdemu = false; // CDEMU object
	private $iso_lba = 0; // LBA Entry Point
	private $iso_vd = false; // Volume Descriptor
	private $iso_pt = false; // Path Table
	private $iso_dr = false; // Directory Records
	private $iso_ext = false; // Extension
	private $iso_map = false; // Filesystem LBA Map
	private $iso_file_map = false; // File LBA Map
	
	// Sets CDEMU object
	public function set_cdemu ($cdemu) {
		if (!is_object ($cdemu))
			return (false);
		$this->o_cdemu = $cdemu;
		return (true);
	}
	
	// Initilize filesystem for usage
	public function init ($lba = false) {
		if ($this->o_cdemu === false) // CDEMU check
			return (false);
		$this->iso_lba = $lba === false ? 0 : $lba;
		$this->iso_vd = array();
		$this->iso_pt = array();
		$this->iso_dr = array();
		$this->iso_ext = array();
		$this->iso_map = array();
		$this->iso_file_map = array();
		if ($this->process_volume_descriptor() === false)
			return (false);
		$this->process_path_table();
		$this->process_root_directory_record();
		return (true);
	}
	
	// Returns Volume Descriptor Array
	public function get_volume_descriptor() {
		return ($this->iso_vd);
	}
	
	// Returns Path Table
	public function get_path_table() {
		return ($this->iso_pt);
	}
	
	// Returns Directory Records
	public function get_directory_record() {
		return ($this->iso_dr);
	}
	
	// Returns iso9660 extension array
	public function get_extension() {
		return ($this->iso_ext);
	}
	
	// Returns iso9660 filesystem lba map array
	public function get_filesystem_map() {
		ksort ($this->iso_map, SORT_NUMERIC);
		return ($this->iso_map);
	}
	
	// Returns file lba map array
	public function get_file_map() {
		ksort ($this->iso_file_map, SORT_NUMERIC);
		return ($this->iso_file_map);
	}
	
	// Check if string consists of only ASCII a characters
	// Note: Fails if padding is included
	public function is_a_char ($string) {
	    $iso_a_char = array ('A', 'B', 'C', 'D', 'E', 'F', 'G',
	                         'H', 'I', 'J', 'K', 'L', 'M', 'N',
	                         'O', 'P', 'Q', 'R', 'S', 'T', 'U',
	                         'V', 'W', 'X', 'Y', 'Z', '0', '1',
	                         '2', '3', '4', '5', '6', '7', '8',
	                         '9', '_', '!', '"', '%', '&', "'",
	                         '(', ')', '*', '+', ',', '-', '.',
	                         '/', ':', ';', '<', '=', '>', '?');
		foreach (str_split ($string) as $l) {
			foreach ($iso_a_char as $c) {
				if ($l != $c)
					return (false);
			}
		}
		return (true);
	}
	// Check if string consists of ASCII d characters
	// Note: Fails if padding is included
	public function is_d_char ($string) {
	    $iso_d_char = array ('A', 'B', 'C', 'D', 'E', 'F', 'G',
	                         'H', 'I', 'J', 'K', 'L', 'M', 'N',
	                         'O', 'P', 'Q', 'R', 'S', 'T', 'U',
	                         'V', 'W', 'X', 'Y', 'Z', '0', '1',
	                         '2', '3', '4', '5', '6', '7', '8',
	                         '9', '_');
		foreach (str_split ($string) as $l) {
			foreach ($iso_d_char as $c) {
				if ($l != $c)
					return (false);
			}
		}
		return (true);
	}
	
	// Remove version information from file_id
	public function &format_fileid ($file_id) {
		if (($pos = strpos ($file_id, ';')) === false)
			return ($file_id);
		$format = substr ($file_id, 0, $pos);
		return ($format);
	}
	
	// Returns version information from file_id
	public function get_fileid_version ($file_id) {
		if (($pos = strpos ($file_id, ';')) === false)
			return (false);
		$ver = substr ($file_id, $pos + 1);
		return ($ver);
	}
	
	// Read System Area from first 16 sectors of filesystem
	//   $file_out: If set, data is saved and information returned, if not set the data is returned inside information array
	//   $hash_algos: Multiple hash algos can be supplied by array ('sha1', 'crc32b')
	public function &read_system_area ($file_out = false, $hash_algos = false) {
		$fail = false;
		$r_info = array();
		if (($hash_algos = cdemu_hash_validate ($hash_algos)) !== false) {
			foreach ($hash_algos as $algo)
				$r_info['hash'][$algo] = hash_init ($algo); // Init hash
		}
		$r_info['lba'] = $this->iso_lba;
		$r_info['data'] = '';
		for ($i = 0; $i < 16; $i++) {
			$data = $this->o_cdemu->read ($this->iso_lba + $i);
			if (!isset ($data['data']))
				return ($fail);
			$r_info['data'] .= $data['data'];
			if ($hash_algos !== false) {
				foreach ($r_info['hash'] as $hash)
					hash_update ($hash, $data['data']);
			}
		}
		$r_info['length'] = strlen ($r_info['data']);
		if ($hash_algos !== false) {
			foreach ($r_info['hash'] as $algo => $hash)
				$r_info['hash'][$algo] = hash_final ($hash, false);
		}
		if ($file_out !== false) {
			if (file_put_contents ($file_out, $r_info['data']) === false)
				return ($fail);
			unset ($r_info['data']);
		}
		return ($r_info);
	}
	
	// Array of files and directories
	public function get_content ($dir = '/', $recursive = true, $metadata = false) {
		$cd = array (''); // Current directory
		$fl = array(); // Output File List
		$files = $this->iso_dr; // Files
		$dir = explode ('/', $dir);
		foreach ($dir as $d) { // Loop through $dir, fill in $cd and get records
			if ($d == null)
				continue;
			$cd[] = $d;
			$f = false;
			foreach ($files as $file) { // Seek Files Records
				if ($file['file_flag']['directory'] and $file['file_id'] == $d) {
					$f = true;
					$files = $file['contents']; // Update files
					break;
				}
			}
			if (!$f) // File not found
				return (false);
		}
		$cd[] = '';
		foreach ($files as $file) {
			if (isset ($file['contents']))
				unset ($file['contents']);
			if ($file['file_flag']['directory']) {
				if ($metadata)
					$fl[implode ('/', $cd) . $file['file_id'] . '/'] = $file;
				else
					$fl[] = implode ('/', $cd) . $file['file_id'] . '/';
				if ($recursive)
					$fl = array_merge ($fl, $this->get_content (implode ('/', $cd) . $file['file_id'] . '/', true, $metadata));
			} else {
				if ($metadata)
					$fl[implode ('/', $cd) . $file['file_id']] = $file;
				else
					$fl[] = implode ('/', $cd) . $file['file_id'];
			}
		}
		return ($fl);
	}
	
	// Returns directory record and parsed file information for file located at $path
	public function &find_file ($path, $trim_overlap = false) {
		$files = $this->iso_dr;
		$path = explode ('/', $path);
		$fail = false;
		$f = false;
		foreach ($path as $d) {
			if ($d == null)
				continue;
			foreach ($files as $file) { // Seek Files List
				if ($file['file_flag']['directory'] and $file['file_id'] == $d) { // Directory
					$files = $file['contents']; // Update files
					break;
				} else if (!$file['file_flag']['directory'] and ($file['file_id'] == $d)) { // File
					$f = true;
					break 2;
				}
			}
		}
		if (!$f)
			return ($fail); // File not found
		$f_info = array();
		$f_info['type'] = ISO9660_FILE; // Regular File
		$f_info['record'] = $file;
		$f_info['lba'] = $file['ex_loc_be']; // Address
		$f_info['length'] = ceil ($file['data_len_be'] / 2048); // Calculate length in sectors
		// if ($file['ex_loc_be'] != $file['ex_loc_le']) // TODO: Detect hidden file data
		if (isset ($file['extension']['xa'])) { // Process XA
			if ($file['extension']['xa']['attributes']['cdda']) {
				$f_info['type'] = ISO9660_FILE_CDDA; // CDDA Link
				$f_info['lba'] -= 150; // Adjust address backwards 2 seconds
				$f_info['length'] += 150; // Adjust length by 150 sectors
				$f_info['track'] = $this->o_cdemu->get_track_by_sector ($f_info['lba']); // Track
			} else if ($file['extension']['xa']['attributes']['interleaved'] or $file['extension']['xa']['attributes']['form2'])
				$f_info['type'] = ISO9660_FILE_XA;
			else
				$f_info['filesize'] = $file['data_len_be']; // Length (Bytes)
		} else
			$f_info['filesize'] = $file['data_len_be']; // Length (Bytes)
		
		if ($trim_overlap) {
			// Check for filesystem overlap
			foreach ($this->iso_map as $lba => $v) {
				if ($f_info['lba'] < $lba and $f_info['lba'] + $f_info['length'] - 1 >= $lba) {
					if (isset ($f_info['filesize']))
						unset ($f_info['filesize']);
					$f_info['length'] = $lba - $f_info['lba'];
				}
			}
			// Check for file overlap
			foreach ($this->iso_file_map as $lba => $v) {
				if ($f_info['lba'] < $lba and $f_info['lba'] + $f_info['length'] - 1 >= $lba) {
					if (isset ($f_info['filesize']))
						unset ($f_info['filesize']);
					$f_info['length'] = $lba - $f_info['lba'];
				}
			}
		}
		return ($f_info);
	}
	
	// Read and count data length from each sector of $f_info and store in $f_info['filesize']
	public function find_filesize (&$f_info, $cb_progress = false) {
		// TODO: CDEMU format short-cut
		if (!is_array ($f_info) or !isset ($f_info['lba']) or !isset ($f_info['length']))
			return (false);
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		$lba_end = $f_info['lba'] + $f_info['length'] - 1;
		$f_info['filesize'] = 0;
		for ($i = $f_info['lba']; $i <= $lba_end; $i++) {
			if (($s = $this->o_cdemu->read ($i)) === false)
				break;
			$f_info['filesize'] += strlen ($s['data']);
			call_user_func ($cb_progress, $lba_end, $i);
		}
		return (false);
	}
	
	// Read file data located at file record $f_info found using find_file
	//   $file_out: If set data is saved and information returned, if not set the data is returned inside information array
	//   $full_dump: If set data is checked to be null before trimming
	//   $raw: Read full sectors
	//   $header: If set and is string, hashed and prepended to output data
	//   $hash_algos: Multiple hash algos can be supplied by array ('sha1', 'crc32b')
	//   $cb_progress: function cli_progress ($length, $pos) { ... }
	// Note: If ISO9660 file version > 1 $file_out is opened with 'a'
	public function &file_read ($f_info, $file_out = false, $full_dump = false, $raw = false, $header = false, $ver_merge = false, $hash_algos = false, $cb_progress = false) {
		$fail = false;
		$r_info = array();
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		if (($hash_algos = cdemu_hash_validate ($hash_algos)) !== false) {
			foreach ($hash_algos as $algo)
				$r_info['hash'][$algo] = hash_init ($algo); // Init hash
		}
		$r_info['data'] = '';
		if ($header !== false and is_string ($header)) {
			$r_info['data'] = $header;
			if ($hash_algos !== false) {
				foreach ($hash_algos as $algo)
					hash_update ($r_info['hash'][$algo], $r_info['data']);
			}
		}
		if (($this->o_cdemu->seek ($f_info['lba'])) === false)
			return ($fail);
		if ($file_out !== false) {
			$r_info['file'] = $file_out;
			$fhm = 'w';
			if ($ver_merge and ($fver = $this->get_fileid_version ($f_info['record']['file_id'])) !== false and $fver > 1) // Handle file versions
				$fhm = 'a';
			$fh = fopen ($file_out, $fhm);
		}
		$size = 0;
		$end = false;
		for ($pos = 0; !$end and $pos < $f_info['length']; $pos++) {
			if (($data = $this->o_cdemu->read()) === false)
				break;
			if ($raw) {
				$size += strlen ($data['sector']);
				$r_info['data'] .= $data['sector'];
			} else if (isset ($f_info['filesize']) and $f_info['filesize'] - $size < strlen ($data['data'])) {
				$t_null = true;
				if ($full_dump) { // Make sure data is null before we chop
					for ($i = $f_info['filesize'] - $size; $i <= strlen ($data['data']) - 1; $i++) {
						if ($data['data'][$i] != "\x00") {
							$t_null = false;
							break;
						}
					}
				}
				if ($t_null)
					$data['data'] = substr ($data['data'], 0, $f_info['filesize'] - $size);
				$size += strlen ($data['data']);
				$r_info['data'] .= $data['data'];
				$end = true;
			} else {
				$size += strlen ($data['data']);
				$r_info['data'] .= $data['data'];
			}
			if ($file_out !== false) {
				fwrite ($fh, $r_info['data']);
				fflush ($fh);
				$r_info['data'] = '';
			}
			if ($hash_algos !== false) {
				foreach ($r_info['hash'] as $hash)
					hash_update ($hash, ($raw ? $data['sector'] : $data['data']));
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $f_info['length'], $pos + 1);
		}
		$r_info['length'] = $size; // Read length
		if (isset ($f_info['filesize']) and $size != $f_info['filesize'])
			$r_info['error']['length'] = $f_info['filesize'];
		if ($end or (isset ($r_info['error']) and isset ($r_info['error']['length']))) {
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $pos + 1, $pos + 1); // Clear
		}
		if ($file_out !== false) {
			fclose ($fh);
			unset ($r_info['data']);
		}
		if ($hash_algos !== false) {
			foreach ($r_info['hash'] as $algo => $hash)
				$r_info['hash'][$algo] = hash_final ($hash, false);
		}
		return ($r_info);
	}
	
	private function process_volume_descriptor() {
		$loc = $this->iso_lba + 16;
		do {
			if (($data = $this->o_cdemu->read ($loc)) === false)
				return (false);
			if (($vd = $this->volume_descriptor ($data['data'])) === false)
				break; // Missing Volume Descriptor Set Terminator
			$this->iso_map[$loc++] = ISO9660_MAP_VOLUME_DESCRIPTOR;
			if ($vd['type'] == 255)
				$loc = false;
			$this->iso_vd[$vd['type']] = $vd;
		} while ($loc !== false);
		if (!isset ($this->iso_vd[1])) {
			$this->iso_vd = false;
			return (false);
		}
		return (true);
	}
	
	private function volume_descriptor ($data) {
		$vd = array();
		$vd['type'] = ord (substr ($data, 0, 1)); // Volume descriptor type
		if (($vd['id'] = substr ($data, 1, 5)) !== "CD001") // Standard Identifier
			return (false);
		$vd['version'] = ord (substr ($data, 6, 1)); // Volume descriptor Version
		switch ($vd['type']) {
			case 0: // Boot Record
				$vd['boot_sys_id']           = substr ($data, 7, 32);
				$vd['boot_id']               = substr ($data, 39, 32);
				$vd['system_use']            = substr ($data, 71, 1977);
				break;
			case 1: // Primary Volume Descriptor
				$vd['unused0']               = substr ($data, 7, 1);
				$vd['sys_id']                = substr ($data, 8, 32);
				$vd['vol_id']                = substr ($data, 40, 32);
				$vd['unused1']               = substr ($data, 72, 8);
				$vd['vol_space_size_le']     = unpack ('V', substr ($data, 80, 4))[1];
				$vd['vol_space_size_be']     = unpack ('N', substr ($data, 84, 4))[1];
				$vd['unused2']               = substr ($data, 88, 32);
				$vd['vol_set_size_le']       = unpack ('v', substr ($data, 120, 2))[1];
				$vd['vol_set_size_be']       = unpack ('n', substr ($data, 122, 2))[1];
				$vd['vol_seq_num_le']        = unpack ('v', substr ($data, 124, 2))[1];
				$vd['vol_seq_num_be']        = unpack ('n', substr ($data, 126, 2))[1];
				$vd['logical_block_le']      = unpack ('v', substr ($data, 128, 2))[1];
				$vd['logical_block_be']      = unpack ('n', substr ($data, 130, 2))[1];
				$vd['pathtable_size_le']     = unpack ('V', substr ($data, 132, 4))[1];
				$vd['pathtable_size_be']     = unpack ('N', substr ($data, 136, 4))[1];
				$vd['lo_pt_l']               = unpack ('V', substr ($data, 140, 4))[1];
				$vd['loo_pt_l']              = unpack ('V', substr ($data, 144, 4))[1];
				$vd['lo_pt_m']               = unpack ('N', substr ($data, 148, 4))[1];
				$vd['loo_pt_m']              = unpack ('N', substr ($data, 152, 4))[1];
				$vd['root_dir_rec']          = $this->directory_record (substr ($data, 156, 34));
				$vd['vol_set_id']            = substr ($data, 190, 128);
				$vd['pub_id']                = substr ($data, 318, 128);
				$vd['data_prep_id']          = substr ($data, 446, 128);
				$vd['application_id']        = substr ($data, 574, 128);
				$vd['copyright_id']          = substr ($data, 702, 37);
				$vd['abstract_id']           = substr ($data, 739, 37);
				$vd['bibliographic_id']      = substr ($data, 776, 37);
				$vd['vol_time_creation']     = $this->iso_date_time (substr ($data, 813, 17));
				$vd['vol_time_modification'] = $this->iso_date_time (substr ($data, 830, 17));
				$vd['vol_time_expiration']   = $this->iso_date_time (substr ($data, 847, 17));
				$vd['vol_time_effective']    = $this->iso_date_time (substr ($data, 864, 17));
				$vd['file_structure_ver']    = ord (substr ($data, 881, 1));
				$vd['reserved0']             = substr ($data, 882, 1);
				$vd['application_use']       = substr ($data, 883, 512);
				$vd['reserved1']             = substr ($data, 1395, 652);
				break;
			case 2: // Supplimentary / Enhanced Volume Descriptor
				$vd['vol_flags']             = ord (substr ($data, 7, 1));
				$vd['sys_id']                = substr ($data, 8, 32);
				$vd['vol_id']                = substr ($data, 40, 32);
				$vd['unused0']               = substr ($data, 72, 8);
				$vd['vol_space_size_le']     = unpack ('V', substr ($data, 80, 4))[1];
				$vd['vol_space_size_be']     = unpack ('N', substr ($data, 84, 4))[1];
				$vd['escape_sequences']      = substr ($data, 88, 32);
				$vd['vol_set_size_le']       = unpack ('v', substr ($data, 120, 2))[1];
				$vd['vol_set_size_be']       = unpack ('n', substr ($data, 122, 2))[1];
				$vd['vol_seq_num_le']        = unpack ('v', substr ($data, 124, 2))[1];
				$vd['vol_seq_num_be']        = unpack ('n', substr ($data, 126, 2))[1];
				$vd['logical_block_le']      = unpack ('v', substr ($data, 128, 2))[1];
				$vd['logical_block_be']      = unpack ('n', substr ($data, 130, 2))[1];
				$vd['pathtable_size_le']     = unpack ('V', substr ($data, 132, 4))[1];
				$vd['pathtable_size_be']     = unpack ('N', substr ($data, 136, 4))[1];
				$vd['lo_pt_l']               = unpack ('V', substr ($data, 140, 4))[1];
				$vd['loo_pt_l']              = unpack ('V', substr ($data, 144, 4))[1];
				$vd['lo_pt_m']               = unpack ('N', substr ($data, 148, 4))[1];
				$vd['loo_pt_m']              = unpack ('N', substr ($data, 152, 4))[1];
				$vd['root_dir_rec']          = $this->directory_record (substr ($data, 156, 34));
				$vd['vol_set_id']            = substr ($data, 190, 128);
				$vd['pub_id']                = substr ($data, 318, 128);
				$vd['data_prep_id']          = substr ($data, 446, 128);
				$vd['application_id']        = substr ($data, 574, 128);
				$vd['copyright_id']          = substr ($data, 702, 37);
				$vd['abstract_id']           = substr ($data, 739, 37);
				$vd['bibliographic_id']      = substr ($data, 776, 37);
				$vd['vol_time_creation']     = $this->iso_date_time (substr ($data, 813, 17));
				$vd['vol_time_modification'] = $this->iso_date_time (substr ($data, 830, 17));
				$vd['vol_time_expiration']   = $this->iso_date_time (substr ($data, 847, 17));
				$vd['vol_time_effective']    = $this->iso_date_time (substr ($data, 864, 17));
				$vd['file_structure_ver']    = ord (substr ($data, 881, 1));
				$vd['reserved0']             = substr ($data, 882, 1);
				$vd['application_use']       = substr ($data, 883, 512);
				$vd['reserved1']             = substr ($data, 1395, 652);
				break;
			case 3: // Volume Partition Descriptor
				$vd['unused0']               = substr ($data, 7, 1);
				$vd['sys_id']                = substr ($data, 8, 32);
				$vd['vol_part_id']           = substr ($data, 40, 32);
				$vd['vol_part_loc_le']       = unpack ('V', substr ($data, 72, 4))[1];
				$vd['vol_part_loc_be']       = unpack ('N', substr ($data, 76, 4))[1];
				$vd['vol_part_size_le']      = unpack ('V', substr ($data, 80, 4))[1];
				$vd['vol_part_size_be']      = unpack ('N', substr ($data, 84, 4))[1];
				$vd['system_use']            = substr ($data, 88, 1960);
				break;
			case 255: // Volume Descriptor Set Terminator
				$vd['reserved0']             = substr ($data, 7, 2041); // Reserved (Zero)
				break;
			default: // Reserved (invalid)
				return (false);
		}
		return ($vd);
	}
	
	private function process_path_table() {
		$this->iso_pt = array();
		$sec = ceil ($this->iso_vd[1]['pathtable_size_le'] / 2048);
		$this->iso_pt['lo_pt_l'] = $this->process_path_table_sub ($this->iso_vd[1]['lo_pt_l'], $sec, 0);
		$this->iso_pt['loo_pt_l'] = $this->process_path_table_sub ($this->iso_vd[1]['loo_pt_l'], $sec, 0);
		$sec = ceil ($this->iso_vd[1]['pathtable_size_be'] / 2048);
		$this->iso_pt['lo_pt_m'] = $this->process_path_table_sub ($this->iso_vd[1]['lo_pt_m'], $sec, 1);
		$this->iso_pt['loo_pt_m'] = $this->process_path_table_sub ($this->iso_vd[1]['loo_pt_m'], $sec, 1);
	}
	
	private function process_path_table_sub ($loc, $sec, $endian) {
		$out = array();
		$raw = '';
		do {
			if (($data = $this->o_cdemu->read ($loc)) === false)
				return (false);
			$this->iso_map[$loc] = ISO9660_MAP_PATH_TABLE;
			$raw .= $data['data'];
			$loc++;
		} while (--$sec > 0);
		while (($pt = $this->path_table ($raw, $endian))['di_len'] > 0) {
			$out = $pt;
			$raw = substr ($raw, 8 + $pt['di_len'] + ($pt['di_len'] % 2 != 0 ? 1 : 0));
		}
		return ($out);
	}
	
	private function path_table ($data, $endian) {
		$pt = array();
		$pt['di_len'] = ord (substr ($data, 0, 1)); // Directory Identifier Length
		$pt['ex_len'] = ord (substr ($data, 1, 1)); // Extended Attribute Record Length
		$pt['ex_loc'] = unpack (($endian ? 'N' : 'V'), substr ($data, 2, 4))[1]; // Location of Extent
		$pt['pd_num'] = unpack (($endian ? 'n' : 'v'), substr ($data, 6, 2))[1]; // Parent Directory Number
		$pt['dir_id'] = substr ($data, 8, $pt['di_len']); // Directory Identifier
		if ($pt['di_len'] % 2 != 0)
			$pt['di_pad'] = substr ($data, 8 + $pt['di_len'], 1); // Padding
		return ($pt);
	}
	
	private function process_root_directory_record() {
		$this->iso_dr = $this->process_directory_record ($this->iso_vd[1]['root_dir_rec']['ex_loc_be']);
	}
	
	private function process_directory_record ($loc) {
		$dir = array();
		$sec = 0;
		do {
			if (($data = $this->o_cdemu->read ($loc)) === false)
				break;
			$dr = $this->directory_record ($data['data']);
			$sec = ($sec == 0 and isset ($dr['data_len_be'])) ? $dr['data_len_be'] / 2048 : $sec;
			while ($dr['dr_len'] > 0) {
				if ($dr['file_id'] != "\x00" and $dr['file_id'] != "\x01") { // Check for . and .. records
					if ($dr['file_flag']['directory'])
						$dr['contents'] = $this->process_directory_record ($dr['ex_loc_be']);
					else
						$this->iso_file_map[$dr['ex_loc_be']] = $dr['file_id'];
					$dir[] = $dr;
				}
				$data['data'] = substr ($data['data'], $dr['dr_len']);
				$dr = $this->directory_record ($data['data']);
			}
			$this->iso_map[$loc] = ISO9660_MAP_DIRECTORY_RECORD;
			$loc++;
		} while (--$sec > 0);
		return ($dir);
	}
	
	private function directory_record ($data) {
		$dr = array();
		$dr['dr_len']         = ord (substr ($data, 0, 1)); // Directory Record Length
		if ($dr['dr_len'] == 0)
			return ($dr);
		$dr['ex_len']         = ord (substr ($data, 1, 1)); // Extended Attribute Record Length
		$dr['ex_loc_le']      = unpack ('V', substr ($data, 2, 4))[1]; // Location of Extent
		$dr['ex_loc_be']      = unpack ('N', substr ($data, 6, 4))[1]; // Location of Extent
		$dr['data_len_le']    = unpack ('V', substr ($data, 10, 4))[1]; // Data Length
		$dr['data_len_be']    = unpack ('N', substr ($data, 14, 4))[1]; // Data Length
		$dr['recording_date'] = $this->iso_date_time (substr ($data, 18, 7)); // Recording Date/Time
		$flags = array();
		$flags['existance']   = (ord (substr ($data, 25, 1)) >> 0) & 0x01; // Existance
		$flags['directory']   = (ord (substr ($data, 25, 1)) >> 1) & 0x01; // Directory
		$flags['assoc_file']  = (ord (substr ($data, 25, 1)) >> 2) & 0x01; // Associated File
		$flags['record']      = (ord (substr ($data, 25, 1)) >> 3) & 0x01; // Record
		$flags['protection']  = (ord (substr ($data, 25, 1)) >> 4) & 0x01; // Protection
		$flags['reserved0']   = (ord (substr ($data, 25, 1)) >> 5) & 0x01; // Reserved
		$flags['reserved1']   = (ord (substr ($data, 25, 1)) >> 6) & 0x01; // Reserved
		$flags['multiextent'] = (ord (substr ($data, 25, 1)) >> 7) & 0x01; // Multi-Extent
		$dr['file_flag']      = $flags;
		$dr['il_fu_size']     = ord (substr ($data, 26, 1)); // Interleave File Unit Size
		$dr['il_gap_size']    = ord (substr ($data, 27, 1)); // Interleave Gap Size
		$dr['vol_seq_num_le'] = unpack ('v', substr ($data, 28, 2))[1]; // Volume Sequence Number
		$dr['vol_seq_num_be'] = unpack ('n', substr ($data, 30, 2))[1]; // Volume Sequence Number
		$dr['fi_len']         = ord (substr ($data, 32, 1)); // Length of File Identifier
		$dr['file_id']        = substr ($data, 33, $dr['fi_len']); // File Identifier
		if ($dr['fi_len'] % 2 != 0)
			$dr['fi_pad'] = substr ($data, 33 + $dr['fi_len'], 1);
		$dr['system_use']     = substr ($data, 34 + $dr['fi_len'] - ($dr['fi_len'] % 2 != 0 ? 1 : 0), ($dr['dr_len'] + ($dr['fi_len'] % 2 != 0 ? 1 : 0) - 34 - $dr['fi_len'])); // System Use
		$dr['extension'] = array();
		$this->directory_record_xa ($dr); // Detect and process XA
		return ($dr);
	}
	
	private function directory_record_xa (&$dr) {
		if (substr ($dr['system_use'], 6, 2) != "XA")
			return;
		if (!isset ($this->iso_ext['xa']))
			$this->iso_ext['xa'] = 1;
		$xa = array();
		$xa['data'] = substr ($dr['system_use'], 0, 14);
		$xa['owner'] = substr ($dr['system_use'], 0, 4);
		$xa['permissions'] = array();
		$xa['permissions']['owner_read']    = (ord (substr ($dr['system_use'], 5, 1)) >> 0) & 0x01;
		$xa['permissions']['owner_execute'] = (ord (substr ($dr['system_use'], 5, 1)) >> 2) & 0x01;
		$xa['permissions']['group_read']    = (ord (substr ($dr['system_use'], 5, 1)) >> 4) & 0x01;
		$xa['permissions']['group_execute'] = (ord (substr ($dr['system_use'], 5, 1)) >> 6) & 0x01;
		$xa['permissions']['world_read']    = (ord (substr ($dr['system_use'], 4, 1)) >> 0) & 0x01;
		$xa['permissions']['world_execute'] = (ord (substr ($dr['system_use'], 4, 1)) >> 2) & 0x01;
		$xa['attributes'] = array();
		$xa['attributes']['form1']          = (ord (substr ($dr['system_use'], 4, 1)) >> 3) & 0x01;
		$xa['attributes']['form2']          = (ord (substr ($dr['system_use'], 4, 1)) >> 4) & 0x01;
		$xa['attributes']['interleaved']    = (ord (substr ($dr['system_use'], 4, 1)) >> 5) & 0x01;
		$xa['attributes']['cdda']           = (ord (substr ($dr['system_use'], 4, 1)) >> 6) & 0x01;
		$xa['attributes']['directory']      = (ord (substr ($dr['system_use'], 4, 1)) >> 7) & 0x01;
		$xa['file_number']                  = ord (substr ($dr['system_use'], 8, 1));
		$xa['reserved']                     = substr ($dr['system_use'], 9, 4);
		$dr['extension']['xa'] = $xa;
	}
	
	private function iso_date_time ($data) {
		$dt = array();
		if (strlen ($data) == 7) {
			$dt['year']  = ord (substr ($data, 0, 1)) + 1900; // Years Since 1900
			$dt['month'] = ord (substr ($data, 1, 1)); // Month (1 - 12)
			$dt['day']   = ord (substr ($data, 2, 1)); // Day (1 - 31)
			$dt['hour']  = ord (substr ($data, 3, 1)); // Hour (0 - 23)
			$dt['min']   = ord (substr ($data, 4, 1)); // Minute (0 - 59)
			$dt['sec']   = ord (substr ($data, 5, 1)); // Second (0 - 59)
			$dt['gmt']   = ord (substr ($data, 6, 1)); // Greenwich Mean Time Offset (GMT-12(West) to GMT+13(East))
		} else if (strlen ($data) == 17) {
			$dt['year']  = (int)substr ($data, 0, 4); // Year (0 - 9999)
			$dt['month'] = (int)substr ($data, 4, 2); // Month (1 - 12)
			$dt['day']   = (int)substr ($data, 6, 2); // Day (1 - 31)
			$dt['hour']  = (int)substr ($data, 8, 2); // Hour (0 - 23)
			$dt['min']   = (int)substr ($data, 10, 2); // Minute (0 - 59)
			$dt['sec']   = (int)substr ($data, 12, 2); // Second (0 - 59)
			$dt['hsec']  = (int)substr ($data, 14, 2); // Hundreth Second (0 - 99)
			$dt['gmt']   = ord (substr ($data, 16, 1)); // Greenwich Mean Time Offset (GMT-12(West) to GMT+13(East))
		} else
			return (false);
		
		$gmt = $dt['gmt'] * 0.25 - 12.00;
		$gmt = ($gmt > 0 ? "+" : "-") .
		        str_pad (abs (floor ($gmt)), 2, '0', STR_PAD_LEFT) . ":" .
		        str_pad ((($gmt - floor ($gmt)) * 4 * 15), 2, '0', STR_PAD_LEFT);
		
		$dt['string_format'] = "Y-n-j G:i:s" . (isset ($dt['hsec']) ? ".v" : "") . "P";
		$dt['string'] = $dt['year'] . "-" .
		                $dt['month'] . "-" .
		                $dt['day'] . " " .
		                $dt['hour'] . ":" . 
		                str_pad ($dt['min'], 2, '0', STR_PAD_LEFT) . ":" .
		                str_pad ($dt['sec'], 2, '0', STR_PAD_LEFT) .
		                (isset ($dt['hsec']) ? "." . str_pad ($dt['hsec'] * 10, 3, '0', STR_PAD_LEFT) : '') . 
		                $gmt;
		return ($dt);
	}
}

?>