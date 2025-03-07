#!/usr/bin/env php
<?php

// Title: CD-ROM Image Dumper
// Description: Dumps CD images to directories
// Author: Greg Michalik

include ('include/cdrom/cdemu.const.php');
include ('include/cdrom/cdemu.common.php');
include ('include/cdrom/cdemu.php');
include ('include/cdrom/cdemu.iso9660.php');

const VERSION = '0.1';

// Application modes
const MODE_DUMP = 0;
const MODE_EXPORT = 1;
$cli = array ('verbose' => false);

cli_process_argv ($argv);

function cli_process_argv ($argv) {
	global $cli;
	$hash_algos = ['crc32b', 'md5', 'sha1', 'sha256'];
	$hash_algos = cdemu_hash_validate ($hash_algos);
	$e_format = ['cdemu', 'cue', 'iso'];
	$hash = false;
	$name = false;
	$dir_out = "output/";
	$mode = MODE_DUMP;
	$filename_trim = false;
	$cdda_symlink = false;
	$xa_riff = false;
	$ram = false;
	$cue_track = false;
	$hash_json = false;
	echo ("Disc Image Tools v" . VERSION . "\n");
	if (count ($argv) == 1)
		cli_display_help ($name, $dir_out, false, $hash_algos, $e_format);
	for ($i = 1; $i < count ($argv); $i++) {
		switch ($argv[$i]) {
			case '-cdemu':
				if (isset ($cue) or isset ($iso) or isset ($bin) or !isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				$index = $argv[$i + 1];
				if (!is_file ($index))
					die ("Error: Can not access '$index'\n");
				$i++;
				break;
			case '-cue':
				if (isset ($index) or isset ($iso) or isset ($bin) or !isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				$cue = $argv[$i + 1];
				if (!is_file ($cue))
					die ("Error: Can not access '$cue'\n");
				$i++;
				break;
			case '-iso':
				if (isset ($index) or isset ($cue) or isset ($bin) or !isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				$iso = $argv[$i + 1];
				if (!is_file ($iso))
					die ("Error: Can not access '$iso'\n");
				$i++;
				break;
			case '-bin':
				if (isset ($index) or !isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				if (isset ($bin))
					$bin[] = $argv[$i + 1];
				else
					$bin = array ($argv[$i + 1]);
				if (!is_file ($argv[$i + 1]))
					die ("Error: Can not access '" . $argv[$i + 1] . "'\n");
				$i++;
				break;
			case '-dir':
				if (!isset ($argv[$i + 1]))
					die ("Error: Missing output directory\n");
				$dir_out = $argv[$i + 1];
				if (substr ($dir_out, -1, 1) != '/')
					$dir_out .= '/';
				$i++;
				break;
			case '-cue_track':
				$cue_track = true;
				break;
			case '-export':
				if (!isset ($argv[$i + 1]))
					die ("Error: Missing export format\n");
				$format = $argv[$i + 1];
				if (is_numeric ($format)) {
					$format = (int)$format;
					if (!isset ($e_format[$format]))
						die ("Error: Export format $format does not exist");
				} else if (is_string ($format)) {
					$f = false;
					foreach ($e_format as $e_i => $e_t) {
						if ($e_t == strtolower ($format)) {
							$format = $e_i;
							$f = true;
						}
					}
					if (!$f)
						die ("Error: Export format $format does not exist");
				}
				$mode = MODE_EXPORT;
				$i++;
				break;
			case '-name':
				if (!isset ($argv[$i + 1]))
					die ("Error: Missing output name\n");
				$name = $argv[$i + 1];
				$i++;
				break;
			case '-dump':
				$mode = MODE_DUMP;
				break;
			case '-trim_name':
				$filename_trim = true;
				break;
			case '-link':
				$cdda_symlink = true;
				break;
			case '-riff':
				$xa_riff = true;
				break;
			case '-ram':
				$ram = true;
				break;
			case '-hash':
				$hash = true;
				break;
			case '-hash_json':
				$hash_json = true;
				break;
			case '-verbose':
				$cli['verbose'] = true;
				break;
			case '-hashes':
				cli_display_help (true, $hash_algos, $e_format);
			case '-hash_set':
				if (!isset ($argv[$i + 1]))
					die ("Error: Missing output name\n");
				$hash_algos = cdemu_hash_validate (explode ("|", $argv[$i + 1]));
				$i++;
				break;
			default:
				cli_display_help (false, $hash_algos, $e_format);
		}
	}
	if (!is_dir ($dir_out) and !mkdir ($dir_out, 0777, true))
		die ("Error: Could not create directory '$dir_out'\n");
	if ($name == false) {
		if (isset ($index))
			$name = basename ($index);
		else if (isset ($cue))
			$name = basename ($cue);
		else if (isset ($iso))
			$name = basename ($iso);	
		else if (isset ($bin))
			$name = basename ($bin[0]);
		if (strpos ($name, ".") !== false) {
			$name = explode (".", $name);
			unset ($name[count ($name) - 1]);
			$name = implode (".", $name);
		}
	}
	if ($hash and !$hash_json)
		$cli['verbose'] = true;
	else if (!$hash and $hash_json)
		$hash = true;
	$cdemu = new CDEMU;
	if ($ram)
		$cdemu->disable_buffer_limit();
	if (isset ($index) and !$cdemu->load_cdemu_index ($index))
		die ("Error: Failed to load cdemu index file\n");
	
	if (isset ($cue) and !$cdemu->load_cue ($cue))
		die ("Error: Failed to load cue file\n");
	
	if (isset ($iso) and !$cdemu->load_iso ($iso))
		die ("Error: Failed to load iso file\n");
	
	if (isset ($bin)) {
		foreach ($bin as $b) {
			if (!$cdemu->load_bin ($b))
				die ("Error: Failed to load bin file '$b'\n");
		}
	}
	if ($cdemu->get_length (true) == 0)
		die ("Error: No image loaded\n");
	
	if ($mode == MODE_DUMP) {
		$cli['progress'] = " Dumping: ";
		$r_info = dump_image ($cdemu, $dir_out, false, false, $filename_trim, $cdda_symlink, $xa_riff, $hash ? $hash_algos : false);
	} else if ($mode == MODE_EXPORT) {
		switch ($format) {
			case 0: // CDEMU
				$cli['progress'] = " Exporting CDEMU: ";
				$r_info = dump_image ($cdemu, $dir_out, true, $name, false, false, false, $hash ? $hash_algos : false);
				break;
			case 1: // CUE
				$cli['progress'] = " Exporting CUE: ";
				if (($r_info = $cdemu->save_cue ($dir_out, $name, $cue_track, $hash ? $hash_algos : false, 'cli_print_progress')) === false)
					die ("Error: Export of CUE failed");
				foreach ($r_info as $info) {
					echo ("  " . basename ($info['file']) . "\n");
					cli_print_info ($info, '    ');
				}
				break;
			case 2: // ISO
				if ($cdemu->get_track_count() > 1)
					echo (" Warning: ISO does not support multiple tracks, data loss will occur\n");
				$cdemu->seek (0);
				$iso9660 = new CDEMU\ISO9660;
				$iso9660->set_cdemu ($cdemu);
				if ($iso9660->init ($cdemu->get_track_start (true))) {
					$ext = $iso9660->get_extension();
					if (isset ($ext['xa']))
						echo (" Warning: ISO does not support CD-XA, data loss will occur\n");
				}
				unset ($iso9660);
				// TODO: Check for Mode2 sectors and warm
				$cli['progress'] = " Exporting ISO: ";
				echo ("  $name.iso\n");
				if (($r_info = $cdemu->save_iso ($dir_out . $name . ".iso", $hash ? $hash_algos : false, 'cli_print_progress')) === false)
					die ("Error: Export of ISO failed");
				else if (is_array ($r_info) and isset ($r_info['hash']))
					cli_print_info ($r_info, '    ');
				break;
		}
	}
	if ($hash_json) {
		if (count ($r_info) > 0 and !isset ($r_info['files']))
			$r_info = ['files' => $r_info];
		if (isset ($r_info['analytics']))
			unset ($r_info['analytics']);
		foreach ($r_info['files'] as $k1 => $v1) {
			if (isset ($v1['error']))
				unset ($r_info['files'][$k1]['error']);
			if (isset ($v1['file']))
				$r_info['files'][$k1]['file'] = substr ($v1['file'], strlen ($dir_out));
		}
		file_put_contents ($dir_out . "hash.json", json_encode ($r_info));
	}
	$cdemu->eject(); // Eject Disk
}

// Dump image loaded by cdemu
function dump_image ($cdemu, $dir_out, $full_dump, $full_name, $filename_trim, $cdda_symlink, $xa_riff, $hash_algos) {
	$r_info = $cdemu->analyze_image ($full_dump, $hash_algos, 'cli_print_progress'); // Analyze entire image
	$cdemu->enable_sector_access_list();
	if (is_array ($r_info) and isset ($r_info['full']))
		cli_print_info ($r_info['full'], '  ');
	if ($full_dump) {
		$info = dump_analytics ($cdemu, $dir_out, $r_info, $hash_algos); // Dump analytics
		$index = $info['index'];
		foreach ($info['files'] as $f)
			$r_info['files'][] = $f;
	}
	for ($track = 1; $track <= $cdemu->get_track_count(); $track++) { // Dump each track
		$t = str_pad ($track, 2, '0', STR_PAD_LEFT);
		echo ("  Track $t\n");
		if (isset ($r_info['track'][$track]))
			cli_print_info ($r_info['track'][$track], '    ');
		if (!$cdemu->set_track ($track))
			die ("Error: Unexpected end of image!\n");
		if ($cdemu->get_track_type() == CDEMU_TRACK_AUDIO) { // Dump Audio Track
			if ($full_dump) {
				$index['LBA'][] = [str_pad ($cdemu->get_track_start (true), strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT), "CDDA"];
				$file_out = $dir_out . "LBA" . str_pad ($cdemu->get_track_start (true), strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . ".cdda";
			} else
				$file_out = $dir_out . "Track $t.cdda";
			if ($cdemu->save_track ($file_out, false, false, 'cli_print_progress') === false)
				return (false);
			$info = $r_info['track'][$track];
			$info['file'] = $dir_out . $file_out;
			$r_info['files'][] = $info;
		} else { // Dump Data Track
			$info = dump_data ($cdemu, $dir_out, $track, $full_dump, $filename_trim, $cdda_symlink, $xa_riff, $hash_algos);
			if ($full_dump and isset ($info['index']['LBA'])) {
				if (!isset ($index['LBA']))
					$index['LBA'] = $info['index']['LBA'];
				else {
					foreach ($info['index']['LBA'] as $lba)
						$index['LBA'][] = $lba;
				}
			}
			foreach ($info['files'] as $f)
				$r_info['files'][] = $f;
		}
	}
	if ($full_dump) {
		$info = dump_index ($dir_out . $full_name . ".cdemu", $index, $hash_algos); // Dump CDEMU index
		$c_sect = dump_verify ($cdemu, $dir_out . $full_name . ".cdemu");
		if (count ($c_sect) > 0) {
			$info = dump_analytics_condensed ($cdemu, $c_sect, "CDSECT", ".bin", $dir_out, $hash_algos); // Dump sector corrections
			$index['CDSECT'] = $info['index'];
			foreach ($info['files'] as $f)
				$r_info['files'][] = $f;
			
			$info = dump_index ($dir_out . $full_name . ".cdemu", $index, $hash_algos); // Redump CDEMU index
		}
		$r_info['files'][] = $info;
	}
	return ($r_info);
}

// Verify integrity of dump
function &dump_verify ($cdemu, $file) {
	$c_sect = array(); // Sector corrections array
	$cdemu2 = new CDEMU;
	$cdemu2->load_cdemu_index ($file);
	$cdemu->seek (0);
	$cdemu2->seek (0);
	for ($i = 0; $i < $cdemu->get_length (true); $i++) {
		$d1 = $cdemu->read();
		$d2 = $cdemu2->read();
		cli_print_progress ($cdemu->get_length (true), $i + 1);
		if ($d1['sector'] != $d2['sector']) {
			//echo (bin2hex ($d1['sector']) . "\n");
			//echo (bin2hex ($d2['sector']) . "\n");
			//die();
			$c_sect[$i] = $d1['sector'];
		}
	}
	return ($c_sect);
}

// Dump index data to $file
function dump_index ($file, $index, $hash_algos) {
	if (!is_array ($index) or count ($index) == 0)
		return;
	$data = '';
	$data .= "CDEMU " . VERSION . "\n\n";
	foreach ($index as $i1 => $v1) {
		if (!is_array ($v1)) {
			$data .= "$i1 $v1\n";
			continue;
		}
		sort ($v1);
		foreach ($v1 as $i2)
			$data .= "$i1 " . (is_array ($i2) ? implode (" ", $i2) : $i2) . "\n";
	}
	
	if (($r_info = hash_write_file ($file, $data, $hash_algos)) === false)
		die ("Error: Could not write file '$file'\n");
	return ($r_info);
}

// Dump image analytical data to according files
function &dump_analytics ($cdemu, $dir_out, &$r_info, $hash_algos) {
	$ret_info = ['index' => [], 'files' => []];
	if (!is_array ($r_info) or !isset ($r_info['analytics']))
		return ($index);
	$ret_info['index']['LENGTH'] = $cdemu->get_length (true); // Image length in sectors
	$CD = $cdemu->get_layout();
	// TODO: Support session listings (requires CDEMU support)
	// TODO: Support subchannel data (requires CDEMU support)
	foreach ($CD['track'] as $track => $t) {
		foreach ($t['index'] as $ii => $vv)
			$t['index'][$ii] = str_pad ($vv, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT);
		$ret_info['index']['TRACK'][] = [str_pad ($track, 2, "0", STR_PAD_LEFT), ($t['format'] == CDEMU_TRACK_AUDIO ? "AUDIO" : "DATA"), implode (" ", $t['index'])]; // Track/index listings
	}
	if (isset ($r_info['analytics']['form2edc']))
		$ret_info['index']['F2EDC'] = $r_info['analytics']['form2edc'] ? 1 : 0;
	if (isset ($r_info['analytics']['mode'])) {
		$mode = false;
		$lba = false;
		$write = false;
		for ($i = array_key_first ($r_info['analytics']['mode']); $i <= array_key_last ($r_info['analytics']['mode']) + 1; $i++) {
			if (isset ($r_info['analytics']['mode'][$i])) {
				if ($mode === false) {
					$lba = $i;
					$mode = $r_info['analytics']['mode'][$i];
				} else if ($mode != $r_info['analytics']['mode'][$i])
					$write = true;
			}
			if ($mode !== false and (!isset ($r_info['analytics']['mode'][$i]) or $write)) {
				$ret_info['index']['CDMODE'][] = [$mode, str_pad ($lba, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT), str_pad ($i - 1, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT)];
				$lba = $i;
				$mode = isset ($r_info['analytics']['mode'][$i]) ? $r_info['analytics']['mode'][$i] : false;
				$write = false;
			}
		}
	}
	if (isset ($r_info['analytics']['mode']) and count ($ret_info['index']['CDMODE']) > 10) {
		$info = dump_analytics_condensed ($cdemu, $r_info['analytics']['mode'], "CDMODE", ".bin", $dir_out, $hash_algos); // Dump sector mode
		$ret_info['index']['CDMODE'] = $info['index'];
		foreach ($info['files'] as $f)
			$ret_info['files'][] = $f;
	}
	if (isset ($r_info['analytics']['address'])) {
		$info = dump_analytics_condensed ($cdemu, $r_info['analytics']['address'], "CDADDR", ".bin", $dir_out, $hash_algos); // Dump invalid header addresses
		$ret_info['index']['CDADDR'] = $info['index'];
		foreach ($info['files'] as $f)
			$ret_info['files'][] = $f;
	}
	if (isset ($r_info['analytics']['xa'])) {
		$info = dump_analytics_condensed ($cdemu, $r_info['analytics']['xa'], "CDXA", ".bin", $dir_out, $hash_algos); // Dump XA errors
		$ret_info['index']['CDXA'] = $info['index'];
		foreach ($info['files'] as $f)
			$ret_info['files'][] = $f;
	}
	if (isset ($r_info['analytics']['edc'])) {
		$info = dump_analytics_condensed ($cdemu, $r_info['analytics']['edc'], "CDEDC", ".bin", $dir_out, $hash_algos); // Dump EDC errors
		$ret_info['index']['CDEDC'] = $info['index'];
		foreach ($info['files'] as $f)
			$ret_info['files'][] = $f;
	}
	if (isset ($r_info['analytics']['ecc'])) {
		$info = dump_analytics_condensed ($cdemu, $r_info['analytics']['ecc'], "CDECC", ".bin", $dir_out, $hash_algos); // Dump ECC errors
		$ret_info['index']['CDECC'] = $info['index'];
		foreach ($info['files'] as $f)
			$ret_info['files'][] = $f;
	}
	return ($ret_info);
}

// Consense analytical data and write to file
function &dump_analytics_condensed ($cdemu, &$a, $file_prefix, $file_postfix, $dir_out, $hash_algos) {
	$r_info = ['index' => [], 'files' => []];
	$out = '';
	$k_last = array_key_last ($a) + 1;
	for ($i = array_key_first ($a); $i <= $k_last; $i++) {
		if (!isset ($a[$i])) {
			if (strlen ($out) > 0) {
				$r_info['index'][] = str_pad ($p_out, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT);
				$file_out = $file_prefix . str_pad ($p_out, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . $file_postfix;
				echo ("  $file_out\n");
				if (($info = hash_write_file ($dir_out . $file_out, $out, $hash_algos)) === false)
					die ("Error: Could not write file '" . $dir_out . $file_out . "'\n");
				cli_print_info ($info, '    ');
				$r_info['files'][] = $info;
				$out = '';
			}
			if (isset ($p_out))
				unset ($p_out);
			continue;
		}
		if (!isset ($p_out))
			$p_out = $i;
		$out .= $a[$i];
	}
	return ($r_info);
}

// Write $data to $file_out and hash
function hash_write_file ($file_out, &$data, $hash_algos) {
	$r_info = array();
	$r_info['file'] = $file_out;
	$r_info['length'] = strlen ($data);
	if (($hash_algos = cdemu_hash_validate ($hash_algos)) !== false) {
		foreach ($hash_algos as $algo) {
			$r_info['hash'][$algo] = hash_init ($algo);
			hash_update ($r_info['hash'][$algo], $data);
			$r_info['hash'][$algo] = hash_final ($r_info['hash'][$algo], false);
		}
	}
	if (file_put_contents ($file_out, $data) === false)
		return (false);
	return ($r_info);
}

// Dump ISO9660 filesystem data
function dump_filesystem ($cdemu, $iso9660, $dir_out, $file_prefix, $file_postfix, $hash_algos) {
	$map = $iso9660->get_filesystem_map();
	$r_info = ['index' => [], 'files' => []];
	$out = '';
	$k_last = array_key_last ($map) + 1;
	for ($i = array_key_first ($map); $i <= $k_last; $i++) {
		if (!isset ($map[$i])) {
			if (strlen ($out) > 0) {
				$r_info['index'][] = str_pad ($p_out, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT);
				$file_out = $file_prefix . str_pad ($p_out, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . $file_postfix;
				echo ("    $file_out\n");
				if (($info = hash_write_file ($dir_out . $file_out, $out, $hash_algos)) === false)
					die ("Error: Could not write file '" . $dir_out . $file_out . "'\n");
				cli_print_info ($info, '      ');
				$r_info['files'][] = $info;
				$out = '';
			}
			if (isset ($p_out))
				unset ($p_out);
			continue;
		}
		if (!isset ($p_out))
			$p_out = $i;
		$data = $cdemu->read ($i);
		$out .= $data['data'];
	}
	return ($r_info);
}

// Dump data track to $dir_out
function dump_data ($cdemu, $dir_out, $track, $full_dump, $trim_filename, $cdda_symlink, $xa_riff, $hash_algos) {
	$r_info = ['index' => [], 'files' => []];
	$iso9660 = new CDEMU\ISO9660;
	$iso9660->set_cdemu ($cdemu);
	$track_dir = "Track " . str_pad ($track, 2, '0', STR_PAD_LEFT) . "/";
	if (!$full_dump and !is_dir ($dir_out . $track_dir))
		mkdir ($dir_out . $track_dir, 0777, true);
	if ($iso9660->init ($cdemu->get_track_start (true))) { // Process ISO9660 filesystem
		if (!$full_dump and !is_dir ($dir_out . $track_dir . "contents"))
			mkdir ($dir_out . $track_dir . "contents", 0777, true);
		
		// File System
		$info = dump_filesystem ($cdemu, $iso9660, $dir_out . ($full_dump ? '' : $track_dir), "LBA", ".bin", $hash_algos);
		if ($full_dump) {
			foreach ($info['index'] as $in)
				$r_info['index']['LBA'][] = $in;
		}
		foreach ($info['files'] as $f)
			$r_info['files'][] = $f;
		
		// Files
		$contents = $iso9660->get_content ('/', true, true); // List root recursively
		foreach ($contents as $c => $meta) { // Save contents to disk
			if (!$full_dump)
				echo ("    $c\n");
			if (substr ($c, -1, 1) == '/') { // Directory
				if (!$full_dump and !is_dir ($dir_out . $track_dir . "contents" . $c))
					mkdir ($dir_out . $track_dir . "contents" . $c, 0777, true);
				continue;
			}
			if (($f_info = $iso9660->find_file ($c, !$full_dump)) === false) {
				echo ("    Error: File not found\n");
				continue;
			}
			$raw = false;
			$header = false;
			if ($full_dump)
				$file_out = $dir_out . "LBA" . str_pad ($f_info['lba'], strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT) . ".bin";
			else
				$file_out = $dir_out . $track_dir . "contents" . ($trim_filename ? $iso9660->format_fileid ($c) : $c);
			if ($f_info['type'] == ISO9660_FILE_CDDA) { // Link to CDDA track
				if (!$cdda_symlink)
					continue;
				$l_name = basename ($file_out);
				$l_path = substr ($file_out, 0, 0 - strlen ($l_name));
				$target = symlink_relative_path ($dir_out, $l_path) . "Track " . str_pad ($f_info['track'], 2, '0', STR_PAD_LEFT) . ".cdda";
				if (is_file ($l_path . $l_name))
					unlink ($l_path . $l_name);
				symlink ($target, $l_path . $l_name); // Create symlink to CDDA track
				continue;
			} else if ($f_info['type'] == ISO9660_FILE_XA) { // XA
				if (!$full_dump)
					$raw = true;
				if ($xa_riff) {
					$h_riff_fmt_id = "CDXA";
					$h_riff_fmt = $f_info['record']['extension']['xa']['data'] . "\x00\x00";
					$header = "RIFF" . pack ('V', $f_info['length'] + 36) . $h_riff_fmt_id . "fmt " . pack ('V', strlen ($h_riff_fmt)) . $h_riff_fmt . "data" . pack ('V', $f_info['length']);
				}
			}
			if ($full_dump) {
				echo ("    " . "LBA" . str_pad ($f_info['lba'], strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT) . ".bin\n");
				$r_info['index']['LBA'][] = str_pad ($f_info['lba'], strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
			}
			if (($info = $iso9660->file_read ($f_info, $file_out, $full_dump, $raw, $header, $trim_filename, $hash_algos, 'cli_print_progress')) === false) {
				echo ("      Error: No file data, image ended\n");
				continue;
			}
			$r_info['files'][] = $info;
			cli_print_info ($info);
			if (isset ($info['error']) and isset ($info['error']['length']) and (!$full_dump or $info['error']['length'] > $info['length']))
				echo ("      Warning: File may be corrupted, reported length " . $info['error']['length'] . "\n");
		}
		
		// Dump any unaccessed sectors within the data track
		$cdemu->set_track ($track);
		$t_start = $cdemu->get_track_start (true);
		$t_end = $t_start + $cdemu->get_track_length (true) - 1;
		foreach ($cdemu->get_sector_unaccessed_list ($t_start, $t_end) as $sector => $length) {
			if ($full_dump)
				$r_info['index']['LBA'][] = str_pad ($sector, strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
			$file_out = "LBA" . str_pad ($sector, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . ".bin";
			echo ("    $file_out\n");
			$info = $cdemu->save_sector ($dir_out . ($full_dump ? '' : $track_dir) . $file_out, $sector, $length, false, $hash_algos, 'cli_print_progress');
			$r_info['files'][] = $info;
			cli_print_info ($info);
		}
	} else { // Dump unrecognized data track
		$sector = $cdemu->get_track_start (true);
		$length = $cdemu->get_track_length (true);
		if ($full_dump)
			$r_info['index']['LBA'][] = str_pad ($sector, strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
		$file_out = "LBA" . str_pad ($sector, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . ".bin";
		echo ("    $file_out\n");
		$info = $cdemu->save_sector ($dir_out . ($full_dump ? '' : $track_dir). $file_out, $sector, $length, false, $hash_algos, 'cli_print_progress');
		$r_info['files'][] = $info;
		cli_print_info ($info);
	}
	return ($r_info); // Return track descriptor
}

function symlink_relative_path ($target, $link) {
	if (!is_string ($target) or !is_string ($link))
		return (false);
	$l_sub = (substr ($link, -1) == '/') ? 1 : 0;
	$link = explode ('/', $link);
	$target = explode ('/', $target);
	$r_target = '';
	$t = true;
	$t_pos = 0; // Trim
	for ($i = 0; $i < count ($link) - $l_sub; $i++) {
		if ($t and $link[$i] == $target[$i]) {
			$t_pos = $i + 1;
			continue;
		} else if ($t and $link[$i] != $target[$i])
			$t = false;
		$r_target .= '../';
	}
	for ($i = $t_pos; $i < count ($target); $i++) {
		$r_target .= $target[$i];
		if ($i != count ($target) - 1)
			$r_target .= '/';
	}
	return ($r_target);
}

function cli_print_info ($r_info, $pre = '      ') {
	global $cli;
	if (!$cli['verbose'] or !is_array ($r_info))
		return;
	echo ($pre . "Length: " . $r_info['length'] . "\n");
	if (isset ($r_info['hash'])) {
		foreach ($r_info['hash'] as $algo => $res)
			echo ("$pre$algo: $res\n");
	}
}

function cli_print_progress ($length, $pos) {
	global $cli;
	$p = $length > 0 ? $pos / $length * 100 : 0;
	echo ($text = "\r" . (isset ($cli['progress']) ? $cli['progress'] : '') . "$pos / $length = " . (int)$p . "%");
	if ($p == 100)
		echo ("\r" . str_repeat (' ', strlen ($text)) . "\r");
}

function cli_display_help ($name, $dir_out, $hashes, $hash_algos, $e_format) {
	echo ("  Input options:\n");
	echo ("    -cue \"FILE.CUE\"     Open CUE file\n");
	echo ("    -iso \"FILE.ISO\"     Open ISO file\n");
	echo ("    -bin \"FILE.BIN\"     Open BIN file\n");
	echo ("    -cdemu \"FILE.CDEMU\" Open CDEMU file\n\n");
	echo ("  Output options:\n");
	echo ("    -dir \"PATH\"         Output directory [$dir_out]\n");
	echo ("    -export \"FORMAT\"    Export image as selected format\n");
	echo ("    -dump               Dump image contents to local files [default]\n\n");
	echo ("  General options:\n");
	echo ("    -ram                Load all read sectors into ram to increase access speeds\n");
	echo ("    -verbose            Show more information\n\n");
	echo ("  Hashing options:\n");
	echo ("    -hashes             Display supported hash algorithms\n");
	echo ("    -hash_set \"A1|A2\"   Set hashing algorithms");
	if (count ($hash_algos) > 0) {
		echo (" [");
		for ($i = 0; $i < count ($hash_algos); $i++)
			echo ($hash_algos[$i] . ($i + 1 < count ($hash_algos) ? ', ' : ''));
		echo ("]\n");
	}
	echo ("    -hash               Enable hashing\n");
	echo ("    -hash_json          Save hashing information to file \"hash.json\"\n\n");
	echo ("  Dump options:\n");
	echo ("    -trim_name          Trim version information from ISO9660 filenames\n");
	echo ("    -link               Create symbolic links for XA-CDDA files\n");
	echo ("    -riff               Dump XA interleaved files to RIFF-CDXA\n\n");
	echo ("  Export options:\n");
	echo ("    -name \"NAME\"        Set output filename without extension [Input filename]\n");
	echo ("    -cue_track          Export BIN file per track\n\n");
	echo ("  Export Formats:\n");
	echo ("    ID     NAME:\n");
	foreach ($e_format as $i => $name)
		echo ("     $i      $name\n");
	if ($hashes) {
		echo ("\n");
		echo ("  Hash Algorithms:\n");
		foreach (hash_algos() as $hash)
			echo ("    $hash\n");
	}
	echo ("\n");
	die();
}

?>