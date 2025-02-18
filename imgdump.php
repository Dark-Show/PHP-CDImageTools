#!/usr/bin/env php
<?php

// Title: CD-ROM Image Dumper
// Description: Dumps CD images to directories
// Author: Greg Michalik
const VERSION = '0.1';

include ('include/cdrom/cdemu.const.php');
include ('include/cdrom/cdemu.common.php');
include ('include/cdrom/cdemu.php');
include ('include/cdrom/cdemu.iso9660.php');

cli_process_argv ($argv);

function cli_process_argv ($argv) {
	echo ("CD-ROM Image Dumper v" . VERSION . "\n");
	if (count ($argv) == 1)
		cli_display_help ($argv);
	$dir_out = "output/";
	$hash_algos = false;
	$full_dump = false;
	$filename_trim = false;
	$cdda_symlink = false;
	$xa_riff = false;
	for ($i = 1; $i < count ($argv); $i++) {
		switch ($argv[$i]) {
			case '-cue':
				if (isset ($iso) or isset ($bin) or !isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				$cue = $argv[$i + 1];
				if (!is_file ($cue))
					die ("Error: Can not access '$cue'\n");
				$i++;
				break;
			case '-iso':
				if (isset ($cue) or isset ($bin) or !isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				$iso = $argv[$i + 1];
				if (!is_file ($iso))
					die ("Error: Can not access '$iso'\n");
				$i++;
				break;
			case '-bin':
				if (!isset ($argv[$i + 1]))
					die ("Error: Invalid arguments\n");
				if (isset ($bin))
					$bin[] = $argv[$i + 1];
				else
					$bin = array ($argv[$i + 1]);
				if (!is_file ($argv[$i + 1]))
					die ("Error: Can not access '" . $argv[$i + 1] . "'\n");
				$i++;
				break;
			case '-output':
				if (!isset ($argv[$i + 1]))
					die ("Error: Missing output directory\n");
				$dir_out = $argv[$i + 1];
				if (substr ($dir_out, -1, 1) != '/')
					$dir_out .= '/';
				$i++;
				break;
			case '-full':
				$full_dump = true;
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
			case '-hash':
				$hash_algos = ['crc32b', 'sha256', 'md5'];
				break;
			default:
				cli_display_help ($argv);
		}
	}
	if ($full_dump) {
		$filename_trim = false;
		$cdda_symlink = false;
		$xa_riff = false;
	}
	if (!is_dir ($dir_out) and !mkdir ($dir_out, 0777, true))
		die ("Error: Could not create directory '$dir_out'\n");
	
	$cdemu = new CDEMU;
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
	dump_image ($cdemu, $dir_out, $full_dump, $filename_trim, $cdda_symlink, $xa_riff, $hash_algos);
	$cdemu->eject(); // Eject Disk
}

// Dump image loaded by cdemu
function dump_image ($cdemu, $dir_out, $full_dump, $filename_trim, $cdda_symlink, $xa_riff, $hash_algos) {
	$index = array();
	$r_info = $cdemu->analyze_image ($full_dump, $hash_algos, 'cli_dump_progress'); // Analyze entire image
	$cdemu->enable_sector_access_list();
	if (is_array ($r_info) and isset ($r_info['hash']) and isset ($r_info['hash']['full']))
		cli_print_hashes ($r_info['hash']['full'], $pre = '  ');
	if ($full_dump) {
		$index['LENGTH'] = $cdemu->get_length (true); // Image length in sectors
		$CD = $cdemu->get_layout();
		// TODO: Support session listings (requires CDEMU support)
		foreach ($CD['track'] as $track => $t) {
			foreach ($t['index'] as $ii => $vv)
				$t['index'][$ii] = str_pad ($vv, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT);
			$index['TRACK'][] = [str_pad ($track, 2, "0", STR_PAD_LEFT), ($t['format'] == CDEMU_TRACK_AUDIO ? "AUDIO" : "DATA"), implode (" ", $t['index'])]; // Track/index listings
		}
		$i = dump_analytics ($cdemu, $dir_out, $r_info, $hash_algos); // Dump analytics
		$index = array_merge ($index, $i);
	}
	for ($track = 1; $track <= $cdemu->get_track_count(); $track++) { // Dump each track
		$t = str_pad ($track, 2, '0', STR_PAD_LEFT);
		echo ("  Track $t\n");
		if (isset ($r_info['hash']['track'][$track]))
			cli_print_hashes ($r_info['hash']['track'][$track], $pre = '    ');
		if (!$cdemu->set_track ($track))
			die ("Error: Unexpected end of image!\n");
		if ($cdemu->get_track_type() == CDEMU_TRACK_AUDIO) {
			if ($full_dump) {
				$index['LBA'][] = [str_pad ($cdemu->get_track_start (true), strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT), "CDDA"];
				$file_out = "LBA" . str_pad ($cdemu->get_track_start (true), strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . ".cdda";
			} else
				$file_out = "Track $t.cdda";
			dump_audio ($cdemu, $dir_out, $file_out);
		} else {
			$i = dump_data ($cdemu, $dir_out, "Track $t/", $full_dump, $filename_trim, $cdda_symlink, $xa_riff, $hash_algos);
			if (isset ($i['LBA'])) {
				if (!isset ($index['LBA']))
					$index['LBA'] = $i['LBA'];
				else {
					foreach ($i['LBA'] as $lba)
						$index['LBA'][] = $lba;
				}
			}
		}
	}
	if ($full_dump) {
		dump_index ($dir_out . "index.cdemu", $index);
		//TODO: Verify integrity of dump and amend index if needed
	}
	return (true);
}

// Dump index data to $file
function dump_index ($file, $index) {
	if (!is_array ($index) or count ($index) == 0)
		return;
	$fh = fopen ($file, 'w');
	fwrite ($fh, "CDEMU " . VERSION . "\n\n");
	foreach ($index as $i1 => $v1) {
		if (!is_array ($v1)) {
			fwrite ($fh, "$i1 $v1\n");
			continue;
		}
		sort ($v1);
		foreach ($v1 as $i2)
			fwrite ($fh, "$i1 " . (is_array ($i2) ? implode (" ", $i2) : $i2) . "\n");
	}
	fclose ($fh);
}

// Dump image analytical data to according files
function dump_analytics ($cdemu, $dir_out, &$r_info, $hash_algos) {
	$index = array();
	if (!is_array ($r_info) or !isset ($r_info['analytics']))
		return ($index);
	if (isset ($r_info['analytics']['mode']))
		$index['CDMODE'] = dump_analytics_condensed ($cdemu, $r_info['analytics']['mode'], "CDMODE", ".bin", $dir_out, $hash_algos); // Dump sector mode
	if (isset ($r_info['analytics']['address']))
		$index['CDADDR'] = dump_analytics_condensed ($cdemu, $r_info['analytics']['address'], "CDADDR", ".bin", $dir_out, $hash_algos); // Dump invalid header addresses
	if (isset ($r_info['analytics']['xa']))
		$index['CDXA'] = dump_analytics_condensed ($cdemu, $r_info['analytics']['xa'], "CDXA", ".bin", $dir_out, $hash_algos); // Dump XA errors
	if (isset ($r_info['analytics']['edc']))
		$index['CDEDC'] = dump_analytics_condensed ($cdemu, $r_info['analytics']['edc'], "CDEDC", ".bin", $dir_out, $hash_algos); // Dump EDC errors
	if (isset ($r_info['analytics']['ecc']))
		$index['CDECC'] = dump_analytics_condensed ($cdemu, $r_info['analytics']['ecc'], "CDECC", ".bin", $dir_out, $hash_algos); // Dump ECC errors
	return ($index);
}

// Consense analytical data and write to file
function dump_analytics_condensed ($cdemu, &$a, $file_prefix, $file_postfix, $dir_out, $hash_algos) {
	$index = array();
	$out = '';
	$k_last = array_key_last ($a) + 1;
	for ($i = array_key_first ($a); $i <= $k_last; $i++) {
		if (!isset ($a[$i])) {
			if (strlen ($out) > 0) {
				$index[] = str_pad ($p_out, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT);
				$file_out = $file_prefix . str_pad ($p_out, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . $file_postfix;
				echo ("  $file_out\n");
				if (($hash = hash_write_file ($dir_out . $file_out, $out, $hash_algos)) === false)
					die ("Error: Could not write file '" . $dir_out . $file_out . "'\n");
				if (is_array ($hash) and isset ($hash['hash']))
					cli_print_hashes ($hash['hash'], $pre = '    ');
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
	return ($index);
}

// Write $data to $file_out and hash
function hash_write_file ($file_out, &$data, $hash_algos) {
	$r_info = array();
	if (($hash_algos = cdemu_hash_validate ($hash_algos)) !== false) {
		foreach ($hash_algos as $algo) {
			$r_info['hash'][$algo] = hash_init ($algo);
			hash_update ($r_info['hash'][$algo], $data);
			$r_info['hash'][$algo] = hash_final ($r_info['hash'][$algo], false);
		}
	}
	$r_info['length'] = strlen ($data);
	if (file_put_contents ($file_out, $data) === false)
		return (false);
	return ($r_info);
}

// Dump audio track to $file
function dump_audio ($cdemu, $dir_out, $filename) {
	if ($cdemu->save_track ($dir_out . $filename, false, false, 'cli_dump_progress') === false)
		return (false);
	return (true);
}

// Dump data track to $dir_out
function dump_data ($cdemu, $dir_out, $track_dir, $full_dump, $trim_filename, $cdda_symlink, $xa_riff, $hash_algos) {
	$index = array();
	$iso9660 = new CDEMU\ISO9660;
	$iso9660->set_cdemu ($cdemu);
	$cdemu->disable_sector_access_list();
	if (!$full_dump and !is_dir ($dir_out . $track_dir))
		mkdir ($dir_out . $track_dir, 0777, true);
	if ($iso9660->init ($cdemu->get_track_start (true))) { // Process ISO9660 filesystem
		$cdemu->enable_sector_access_list();
		if (!$full_dump and !is_dir ($dir_out . $track_dir . "contents"))
			mkdir ($dir_out . $track_dir . "contents", 0777, true);
		if ($full_dump) {
			$index['LBA'][] = str_pad ($cdemu->get_track_start (true), strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
			$file_out = "LBA" . str_pad ($cdemu->get_track_start (true), strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT) . ".bin";
		} else
			$file_out = "SYSTEM.bin";
		echo ("    $file_out\n");
		$r_info = $iso9660->read_system_area ($dir_out . ($full_dump ? '' : $track_dir) . $file_out, $hash_algos);
		if (isset ($r_info['hash']))
			cli_print_hashes ($r_info['hash']);
		
		$contents = $iso9660->get_content ('/', true, true); // List root recursively
		foreach ($contents as $c => $meta) { // Save contents to disk
			if (!$full_dump)
				echo ("    $c\n");
			if (substr ($c, -1, 1) == '/') { // Directory
				if (!$full_dump and !is_dir ($dir_out . $track_dir . "contents" . $c))
					mkdir ($dir_out . $track_dir . "contents" . $c, 0777, true);
				continue;
			}
			if (($f_info = $iso9660->find_file ($c)) === false) {
				echo ("    Error: File not found!\n");
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
				if ($xa_riff) {
					$raw = true;
					$h_riff_fmt_id = "CDXA";
					$h_riff_fmt = $f_info['record']['extension']['xa']['data'] . "\x00\x00";
					$header = "RIFF" . pack ('V', $f_info['length'] + 36) . $h_riff_fmt_id . "fmt " . pack ('V', strlen ($h_riff_fmt)) . $h_riff_fmt . "data" . pack ('V', $f_info['length']);
				}
			}
			if ($full_dump) {
				echo ("    " . "LBA" . str_pad ($f_info['lba'], strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT) . ".bin\n");
				$index['LBA'][] = str_pad ($f_info['lba'], strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
			}
			if (($r_info = $iso9660->file_read ($f_info, $file_out, $raw, $header, $trim_filename, $hash_algos, 'cli_dump_progress')) === false) {
				echo ("      Error: Image ended prematurely!\n");
				continue;
			} else if (isset ($r_info['error']) and isset ($r_info['error']['length']))
				echo ("      Alert: File may be incomplete, image ended prematurely!\n");
			if (isset ($r_info['hash']))
				cli_print_hashes ($r_info['hash']);
		}
		
		// Dump any unaccessed sectors within the data track
		$t_start = $cdemu->get_track_start (true);
		$t_end = $t_start + $cdemu->get_track_length (true) - 1;
		foreach ($cdemu->get_sector_unaccessed_list ($t_start, $t_end) as $sector => $length) {
			if ($full_dump)
				$index['LBA'][] = str_pad ($sector, strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
			$file_out = "LBA" . str_pad ($sector, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . ".bin";
			echo ("    $file_out\n");
			$hash = $cdemu->save_sector ($dir_out . ($full_dump ? '' : $track_dir) . $file_out, $sector, $length, false, $hash_algos, 'cli_dump_progress');
			cli_print_hashes ($hash);
		}
	} else { // Dump unrecognized data track
		$cdemu->enable_sector_access_list();
		$sector = $cdemu->get_track_start (true);
		$length = $cdemu->get_track_length (true);
		if ($full_dump)
			$index['LBA'][] = str_pad ($sector, strlen ($cdemu->get_length (true)), '0', STR_PAD_LEFT);
		$file_out = "LBA" . str_pad ($sector, strlen ($cdemu->get_length (true)), "0", STR_PAD_LEFT) . ".bin";
		echo ("    $file_out\n");
		$hash = $cdemu->save_sector ($dir_out . ($full_dump ? '' : $track_dir). $file_out, $sector, $length, false, $hash_algos, 'cli_dump_progress');
		cli_print_hashes ($hash);
	}
	return ($index); // Return track descriptor
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

function cli_print_hashes ($hash, $pre = '      ') {
	if (!is_array ($hash))
		return;
	foreach ($hash as $algo => $res)
		echo ("$pre$algo: $res\n");
	echo ("\n");
}

function cli_dump_progress ($length, $pos) {
	$p = $length > 0 ? $pos / $length * 100 : 0;
	echo ($cli = " $pos / $length = " . (int)$p . "%\r");
	if ($p == 100)
		echo (str_repeat (' ', strlen ($cli)) . "\r");
}

function cli_display_help ($argv) {
	echo ("  Arguments:\n");
	echo ("    -cue \"FILE.CUE\"    Input CUE file\n");
	echo ("    -iso \"FILE.ISO\"    Input ISO file\n");
	echo ("    -bin \"FILE.BIN\"    Input BIN file\n");
	echo ("    -output \"PATH/\"    Output directory\n");
	echo ("    -hash              Hash image and output files using: crc32b, sha256, md5\n\n");
	echo ("    -full              Dump image to format that can be reassembled\n");
	echo ("    -trim_name         Trim version information from ISO9660 filenames\n");
	echo ("    -link              Create symbolic links for XA-CDDA files\n");
	echo ("    -riff              Dump XA files to RIFF-CDXA\n");
	echo ("  Example Usages:\n");
	echo ("    " . $argv[0] . " -cue \"input.cue\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -iso \"input.iso\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -bin \"Track01.bin\" -bin \"Track02.bin\" -output \"output/\"\n");
	die();
}

?>