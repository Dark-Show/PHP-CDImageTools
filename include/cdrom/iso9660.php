<?php

//////////////////////////////////////
// Title: ISO9660
// Description: ISO9660 Filesystem Decoder
// Author: Greg Michalik
//////////////////////////////////////
//   Supported Functionality
//   + System area
//   + Directory listing
//     + Metadata
//   + File retrieval
//   + Extensions:
//     + XA
//////////////////////////////////////
namespace CDEMU;
class ISO9660 {
	private $cb_dataread = 0; // Data Read callback
	private $iso_pvd = array(); // Primary Volume Descriptor
	private $iso_pt = array(); // Path Table
	private $iso_dr_loc = array(); // Processed Directory Record Locations
	private $file_list = array(); // File System Contents
	
	// Sets CD-ROM read function callback
	function set_data_read ($callback) {
		if (!is_callable ($callback))
			return (false);
		$this->cb_dataread = $callback;
		return (true);
	}
	
	// Initilize filesystem for usage
	function init ($init_sector = 16) { // Start trying to load filesystem
		$this->iso_dr_loc = array(); // Clear Processed Directory Record Locations
		if (($data = call_user_func ($this->cb_dataread, $init_sector)) === false) // Load Sector 16
			return (false);
		// TODO: Also load supplimental
		if (!$this->iso_pvd = $this->volume_descriptor ($data['data'])) // Load Primary Volume Descriptor
			return (false);
		if (($data = call_user_func ($this->cb_dataread, $this->iso_pvd['lo_pt_m'])) === false) // Get Path Table Location
			return (false);
		$this->iso_pt = $this->path_table (substr ($data['data'], 0, $this->iso_pvd['pathtable_size'])); // Load Path Table
		$this->file_list = $this->process_directory_record ($this->iso_pt['ex_loc']); // Process Root Directory Record
		return (true);
	}
	
	public function get_pvd() {
		return ($this->iso_pvd);
	}
	
	// Load System Area (Sectors 0 - 15)
	function &get_system_area() {
		$fail = false;
		$system_area = '';
		for ($i = 0; $i < 16; $i++) {
			if (($data = call_user_func ($this->cb_dataread, $i)) === false)
				return ($fail);
			$system_area .= $data['data'];
		}
		return ($system_area);
	}
	// Save System Area to $file
	function save_system_area ($file) {
		if (($sa = $this->get_system_area()) === false)
			return (false);
		if (file_put_contents($file, $sa) === false)
			return (false);
		return (true);
	}
	
	// Array of files and directories
	function list_contents ($dir = '/', $recursive = true, $metadata = false) {
		$cd = array (''); // Current directory
		$fl = array(); // Output File List
		$files = $this->file_list; // Files
		$dir = explode ('/', $dir);
		foreach ($dir as $d) { // Loop through $dir, fill in $cd and get records
			if ($d != null) {
				$cd[] = $d;
				$f = false; // File Found
				foreach ($files as $file) { // Seek Files Records
					if ($file['file_flag']['directory'] and $file['file_id'] == $d) {
						$f = true; // Found File
						$files = $file['contents']; // Update files
						break;
					}
				}
				if (!$f) // File not found
					return (false);
			}
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
					$fl = array_merge ($fl, $this->list_contents (implode ('/', $cd) . $file['file_id'] . '/', true, $metadata));
			} else {
				if ($metadata)
					$fl[implode ('/', $cd) . $file['file_id']] = $file;
				else
					$fl[] = implode ('/', $cd) . $file['file_id'];
			}
		}
		return ($fl);
	}
	
	// Remove version information from filename
	function &format_filename ($filename) {
		if (($pos = strpos ($filename, ';')) === false)
			return ($filename);
		$format = substr ($filename, 0, $pos);
		return ($format);
	}
	
	// Dump file data located at $path to disk file location $path_output with optional header
	//   $cb_progress: function cli_progress ($length, $pos) { ... }
	function &save_file ($path, $path_output, $add_header = true, $cb_progress = false) {
		$files = $this->file_list;
		$path = explode ('/', $path);
		foreach ($path as $d) {
			if ($d == null)
				continue;
			foreach ($files as $file) { // Seek Files List
				if ($file['file_flag']['directory'] and $file['file_id'] == $d) { // Directory
					$files = $file['contents']; // Update files
					break;
				} else if (!$file['file_flag']['directory'] and ($file['file_id'] == $d)) // File
					return ($this->file_read ($file, $add_header, $path_output, $cb_progress)); // Read file
			}
		}
		$fail = false;
		return ($fail); // File not found
	}
	
	// Return file data located at $path with optional header
	function &get_file ($path, $add_header = true) {
		$files = $this->file_list;
		$path = explode ('/', $path);
		foreach ($path as $d) {
			if ($d == null)
				continue;
			foreach ($files as $file) { // Seek Files List
				if ($file['file_flag']['directory'] and $file['file_id'] == $d) { // Directory
					$files = $file['contents']; // Update files
					break;
				} else if (!$file['file_flag']['directory'] and ($file['file_id'] == $d)) // File
					return ($this->file_read ($file, $add_header)); // Read file
			}
		}
		$fail = false;
		return ($fail); // File not found
	}
	
	private function &file_read ($file, $add_header, $path_out = false, $cb_progress = false) {
		$fail = false;
		$length = 0;
		if (!is_callable ($cb_progress))
			$cb_progress = false;
		
		// Note: For CDDA referenced files, we use $ex_loc_adj to seek backwards 2sec and add 2sec to the file_length
		$ex_loc_adj = (isset ($file['extension']['xa']) and $file['extension']['xa']['attributes']['cdda']) ? 150 : 0; // Header time starts at 00:02:00
		
		if (($data = call_user_func ($this->cb_dataread, $file['ex_loc'] - $ex_loc_adj)) === false) {
			echo ("Error: Unexpected end of image!\n");
			return ($fail);
		}
		$raw = false;
		$h_riff = false;
		if (isset ($file['extension']['xa']) and $file['extension']['xa']['attributes']['cdda']) {
			$raw = true;
			if ($add_header) {
				$h_wave_type = 1; // PCM
				$h_wave_channels = 2; // Channels
				$h_wave_sample_rate = 44100; // Hertz
				$h_wave_bits_per_sample = 16; // Bits
				$h_wave_bytes_per_sample = ($h_wave_bits_per_sample * $h_wave_channels) / 8; // Bytes
				$h_wave_bytes_per_second = ($h_wave_sample_rate * $h_wave_bits_per_sample * $h_wave_channels) / 8; // Bytes

				$h_riff = true;
				$h_riff_fmt_id = "WAVE";
				$h_riff_fmt = pack ('v', $h_wave_type) . pack ('v', $h_wave_channels) . pack ('V', $h_wave_sample_rate) . 
							  pack ('V', $h_wave_bytes_per_second) . pack ('v', $h_wave_bytes_per_sample) . pack ('v', $h_wave_bits_per_sample);
			}
		} else if (isset ($data['xa']) and ($data['xa']['submode']['audio'] or $data['xa']['submode']['video'] or $data['xa']['submode']['realtime'])) {
			$raw = true;
			// RIFF XA header required
			$h_riff = true;
			$h_riff_fmt_id = "CDXA";
			$h_riff_fmt = $file['extension']['xa']['data'] . "\x00\x00";
		}
		
		if ($raw)
			$file_length = (($file['data_len'] / 2048) + $ex_loc_adj) * 2352;
		else
			$file_length = $file['data_len'];
		
		if ($raw)
			$out = $data['sector'];
		else
			$out = $data['data'];
		
		if (!$raw and $file_length < strlen ($out))
			$out = substr ($out, 0, $file_length - strlen ($out));
		$length += strlen ($out);
		
		if ($raw and $h_riff)
			$out = "RIFF" . pack ('V', $file_length + 36) . $h_riff_fmt_id . "fmt " . pack ('V', strlen ($h_riff_fmt)) . $h_riff_fmt . "data" . pack ('V', $file_length) . $out;
		
		if ($path_out !== false) {
			$fh = fopen ($path_out, 'w');
			fwrite ($fh, $out);
			$out = '';
		}
		
		if ($cb_progress !== false)
			call_user_func ($cb_progress, $file_length, $length);
		
		while ($data !== false and $length < $file_length) {
			if (($data = call_user_func ($this->cb_dataread)) === false) {
				print_r ("Error: Unexpected end of image!\n");
				continue;
			}
			if ($raw) {
				$length += strlen ($data['sector']);
				$out .= $data['sector'];
			} else if ($file_length - $length < strlen ($data['data'])) {
				$data = substr ($data['data'], 0, $file_length - $length);
				$length += strlen ($data);
				$out .= $data;
			} else {
				$length += strlen ($data['data']);
				$out .= $data['data'];
			}
			if ($path_out !== false) {
				fwrite ($fh, $out);
				$out = '';
			}
			if ($cb_progress !== false)
				call_user_func ($cb_progress, $file_length, $length);
		}
		if ($path_out !== false) {
			fclose ($fh);
			$out = true;
		}
		return ($out);
	}
	
	private function volume_descriptor ($data) {
		$vd = array();
		$vd['type'] = ord (substr ($data, 0, 1)); // Volume descriptor type
		
		$vd['id'] = substr ($data, 1, 5); // Standard Identifier
		if ($vd['id'] !== "CD001")
			return (false);
		$vd['version'] = ord (substr ($data, 6, 1)); // Volume descriptor Version
		
		switch ($vd['type']) {
			case 0: // Boot Record
				break;
			case 1: // Primary Volume Descriptor
				$vd['unused0']               = substr ($data, 7, 1);
				$vd['sys_id']                = substr ($data, 8, 32);
				$vd['vol_id']                = substr ($data, 40, 32);
				$vd['unused1']               = substr ($data, 72, 8);
				$vd['vol_space_size']        = unpack ('N', substr ($data, 84, 4))[1];
				$vd['unused2']               = substr ($data, 88, 32);
				$vd['vol_set_size']          = unpack ('n', substr ($data, 122, 2))[1];
				$vd['vol_seq_num']           = unpack ('n', substr ($data, 126, 2))[1];
				$vd['logical_block']         = unpack ('n', substr ($data, 130, 2))[1];
				$vd['pathtable_size']        = unpack ('N', substr ($data, 136, 4))[1];
				$vd['lo_pt_l']               = substr ($data, 140, 4);
				$vd['loo_pt_l']              = substr ($data, 144, 4);
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
			case 255: // Volume Descriptor Set Terminator
				$vd['reserved0']             = substr ($data, 7, 2041); // Reserved (Zero)
				break;
			case 2: // Supplementary Volume Descriptor
				break;
			case 3: // Volume Partition Descriptor
				break;
			default:
				die ('ISO9660 Error: Unknown volume descriptor type!');
		}
		return ($vd);
	}
	
	private function process_directory_record ($loc) {
		$dir = array(); // Directory Listing
	    $data = call_user_func ($this->cb_dataread, $loc); // Get Directory Record Location
		$dr = $this->directory_record ($data['data']); // Load Directory Record
		$this->iso_dr_loc[$dr['ex_loc']] = 1; // Mark Directory Record as processed
		
		while ($dr['dr_len'] > 0) { // While our directory records have length
			if ($dr['file_flag']['directory']) { // Directory check
				if (!isset ($this->iso_dr_loc[$dr['ex_loc']])) {
					$this->iso_dr_loc[$dr['ex_loc']] = 1; // Mark Directory Record as processed
					$dr['contents'] = $this->process_directory_record ($dr['ex_loc']);
					$dir[] = $dr;
				}
			} else
				$dir[] = $dr;
			$data['data'] = substr ($data['data'], $dr['dr_len']);
			$dr = $this->directory_record ($data['data']);
		}
		return ($dir);
	}
	
	private function directory_record ($data) {
		$dr = array(); // Directory Record
		$dr['dr_len'] = ord (substr ($data, 0, 1)); // Directory Record Length
		if ($dr['dr_len'] == 0)
			return ($dr);
		$dr['ex_len'] = ord (substr ($data, 1, 1)); // Extended Attribute Record Length
		$dr['ex_loc'] = unpack ('N', substr ($data, 6, 4))[1]; // Location of Extent
		$dr['data_len'] = unpack ('N', substr ($data, 14, 4))[1]; // Data Length
		$dr['recording_date'] = $this->iso_date_time (substr ($data, 18, 7)); // Recording Date/Time
		$flags = array();
		$flags['existance'] = (ord (substr ($data, 25, 1)) >> 0) & 0x01; // Existance
		$flags['directory'] = (ord (substr ($data, 25, 1)) >> 1) & 0x01; // Directory
		$flags['assoc_file'] = (ord (substr ($data, 25, 1)) >> 2) & 0x01; // Associated File
		$flags['record'] = (ord (substr ($data, 25, 1)) >> 3) & 0x01; // Record
		$flags['protection'] = (ord (substr ($data, 25, 1)) >> 4) & 0x01; // Protection
		$flags['multiextent'] = (ord (substr ($data, 25, 1)) >> 5) & 0x01; // Multi-Extent
		$dr['file_flag'] = $flags;
		
		$dr['il_fu_size'] = ord (substr ($data, 26, 1)); // Interleave File Unit Size
		$dr['il_gap_size'] = ord (substr ($data, 27, 1)); // Interleave Gap Size
		
		$dr['vol_seq_num'] =  unpack ('n', substr ($data, 29, 2))[1]; // Volume Sequence Number
		$dr['fi_len'] = ord (substr ($data, 32, 1)); // Length of File Identifier
		$dr['file_id'] = substr ($data, 33, $dr['fi_len']); // File Identifier
		$dr['system_use'] = substr ($data, (34 + $dr['fi_len'] - ($dr['fi_len'] % 2 != 0 ? 1 : 0)), ($dr['dr_len'] - (34 - $dr['fi_len']))); // System Use
		$dr['extension'] = array();
		if (substr ($dr['system_use'], 6, 2) == "XA") { // Detect XA
			$xa = array();
			$xa['data'] = substr ($dr['system_use'], 0, 14);
			$xa['owner'] = substr ($dr['system_use'], 0, 4);
			$xa['permissions'] = array();
			$xa['permissions']['owner_read'] = (ord (substr ($dr['system_use'], 5, 1)) >> 0) & 0x01;
			$xa['permissions']['owner_execute'] = (ord (substr ($dr['system_use'], 5, 1)) >> 2) & 0x01;
			$xa['permissions']['group_read'] = (ord (substr ($dr['system_use'], 5, 1)) >> 4) & 0x01;
			$xa['permissions']['group_execute'] = (ord (substr ($dr['system_use'], 5, 1)) >> 6) & 0x01;
			$xa['permissions']['world_read'] = (ord (substr ($dr['system_use'], 4, 1)) >> 0) & 0x01;
			$xa['permissions']['world_execute'] = (ord (substr ($dr['system_use'], 4, 1)) >> 2) & 0x01;
			$xa['attributes'] = array();
			$xa['attributes']['form1'] = (ord (substr ($dr['system_use'], 4, 1)) >> 3) & 0x01;
			$xa['attributes']['form2'] = (ord (substr ($dr['system_use'], 4, 1)) >> 4) & 0x01;
			$xa['attributes']['interleaved'] = (ord (substr ($dr['system_use'], 4, 1)) >> 5) & 0x01;
			$xa['attributes']['cdda'] = (ord (substr ($dr['system_use'], 4, 1)) >> 6) & 0x01;
			$xa['attributes']['directory'] = (ord (substr ($dr['system_use'], 4, 1)) >> 7) & 0x01;
			$xa['file_number'] = ord (substr ($dr['system_use'], 8, 1));
			$xa['reserved'] = substr ($dr['system_use'], 9, 4);
			$dr['extension']['xa'] = $xa;
		}
		return ($dr);
	}
	
	private function path_table ($data) {
		$pt = array();
		$pt['di_len'] = ord (substr ($data, 0, 1)); // Directory Identifier Length
		$pt['ex_len'] = ord (substr ($data, 1, 1)); // Extended Attribute Record Length
		$pt['ex_loc'] = unpack ('N', substr ($data, 2, 4))[1]; // Location of Extent
		$pt['pd_num'] = unpack ('n', substr ($data, 6, 2))[1]; // Parent Directory Number
		$pt['dir_id'] = substr ($data, 8, 8 - (7 + $pt['di_len'])); // Directory Identifier
		return ($pt);
	}
	
	private function iso_date_time ($data) {
		$dt = array();
		if (strlen ($data) == 7) {
			$dt['year']   = ord (substr ($data, 0, 1)) + 1900; // Years Since 1900
			$dt['month']  = ord (substr ($data, 1, 1));        // Month (1 - 12)
			$dt['day']    = ord (substr ($data, 2, 1));        // Day (1 - 31)
			$dt['hour']   = ord (substr ($data, 3, 1));        // Hour (0 - 23)
			$dt['min']    = ord (substr ($data, 4, 1));        // Minute (0 - 59)
			$dt['sec']    = ord (substr ($data, 5, 1));        // Second (0 - 59)
			$dt['gmt']    = ord (substr ($data, 6, 1));        // Greenwich Mean Time Offset (GMT-12(West) to GMT+13(East))
		} else if (strlen ($data) == 17) {
			$dt['year']   = (int)substr ($data, 0, 4) + 1900;  // Years Since 1900
			$dt['month']  = (int)substr ($data, 4, 2);         // Month (1 - 12)
			$dt['day']    = (int)substr ($data, 6, 2);         // Day (1 - 31)
			$dt['hour']   = (int)substr ($data, 8, 2);         // Hour (0 - 23)
			$dt['min']    = (int)substr ($data, 10, 2);        // Minute (0 - 59)
			$dt['sec']    = (int)substr ($data, 12, 2);        // Second (0 - 59)
			$dt['hsec']   = (int)substr ($data, 14, 2);        // Hundreth Second (0 - 99)
			$dt['gmt']    = ord (substr ($data, 16, 1));       // Greenwich Mean Time Offset (GMT-12(West) to GMT+13(East))
		} else
			return (false);
		$dt['gmt'] = -12.00 + ($dt['gmt'] * 0.25);
		if ($dt['gmt'] != floor ($dt['gmt']))
			$dt['gmt'] = floor ($dt['gmt']) . ":" . (($dt['gmt'] - floor ($dt['gmt'])) * 4 * 15);
		return ($dt);
	}
}

?>