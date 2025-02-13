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
				if (!is_dir ($dir_out) and !mkdir ($dir_out, 0777, true))
					die ("Error: Could not create directory '$dir_out'\n");
				if (substr ($dir_out, -1, 1) != '/')
					$dir_out .= '/';
				$i++;
				break;
			case '-hash':
				$hash_algos = ['crc32b', 'sha256', 'md5'];
				break;
			default:
				cli_display_help ($argv);
		}
	}
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
	dump_image ($cdemu, $dir_out, $hash_algos);
	$cdemu->eject(); // Eject Disk
}

// Dump image loaded by cdemu
function dump_image ($cdemu, $dir_out, $hash_algos = false) {
	$hash = $cdemu->hash_image ($hash_algos, 'cli_dump_progress'); // Hash entire image
	if (is_array ($hash) and isset ($hash['full']))
		cli_print_hashes ($hash['full'], $pre = '  ');
	$cdemu->clear_sector_access_list();
	// TODO: dump all sectors XA data
	
	// Dump each track
	for ($track = 1; $track <= $cdemu->get_track_count(); $track++) {
		$t = str_pad ($track, 2, '0', STR_PAD_LEFT);
		echo ("  Track $t\n");
		if (isset ($hash['track'][$track]))
			cli_print_hashes ($hash['track'][$track], $pre = '    ');
		if (!$cdemu->set_track ($track))
			die ("Error: Unexpected end of image!\n");
		if ($cdemu->get_track_type() == CDEMU_TRACK_AUDIO)
			dump_audio ($cdemu, $dir_out, "Track $t.cdda");
		else
			dump_data ($cdemu, $dir_out, "Track $t/", true, true, true, $hash_algos);
	}
	return (true);
}

// Dump audio track to $file
function dump_audio ($cdemu, $dir_out, $filename) {
	if ($cdemu->save_track ($dir_out . $filename, false, false, 'cli_dump_progress') === false)
		return (false);
	return (true);
}

// Dump data track to $dir_out
function dump_data ($cdemu, $dir_out, $track_dir, $trim_filename = false, $cdda_symlink = false, $xa_riff = false, $hash_algos = false) {
	$iso9660 = new CDEMU\ISO9660;
	$iso9660->set_cdemu ($cdemu);
	$cdemu->disable_sector_access_list();
	if (!is_dir ($dir_out . $track_dir))
		mkdir ($dir_out . $track_dir, 0777, true);
	if ($iso9660->init ($cdemu->get_track_start (true))) { // Process ISO9660 filesystem
		$cdemu->enable_sector_access_list();
		if (!is_dir ($dir_out . $track_dir . "contents"))
			mkdir ($dir_out . $track_dir . "contents", 0777, true);
		echo ("    SYSTEM.bin\n");
		$r_info = $iso9660->read_system_area ($dir_out . $track_dir . 'SYSTEM.bin', $hash_algos);
		if (isset ($r_info['hash']))
			cli_print_hashes ($r_info['hash']);
		$contents = $iso9660->get_content ('/', true, true); // List root recursively
		foreach ($contents as $c => $meta) { // Save contents to disk
			echo ("    $c\n");
			if (substr ($c, -1, 1) == '/') { // Directory
				if (!is_dir ($dir_out . $track_dir . "contents" . $c))
					mkdir ($dir_out . $track_dir . "contents" . $c, 0777, true);
				continue;
			}
			if (($f_info = $iso9660->find_file ($c)) === false) {
				echo ("    Error: File not found!\n");
				continue;
			}
			$raw = false;
			$header = false;
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
				$raw = true;
				if ($xa_riff) {
					$h_riff_fmt_id = "CDXA";
					$h_riff_fmt = $f_info['record']['extension']['xa']['data'] . "\x00\x00";
					$header = "RIFF" . pack ('V', $f_info['length'] + 36) . $h_riff_fmt_id . "fmt " . pack ('V', strlen ($h_riff_fmt)) . $h_riff_fmt . "data" . pack ('V', $f_info['length']);
				}
			}
			if (($r_info = $iso9660->file_read ($f_info, $file_out, $raw, $header, $hash_algos, 'cli_dump_progress')) === false) {
				echo ("    Error: Image issues!\n");
				continue;
			}
			if (isset ($r_info['hash']))
				cli_print_hashes ($r_info['hash']);
		}
		
		// Dump any unaccessed sectors within the data track
		$t_start = $cdemu->get_track_start (true);
		$t_end = $t_start + $cdemu->get_track_length (true) - 1;
		foreach ($cdemu->get_sector_unaccessed_list ($t_start, $t_end) as $sector => $length) {
			echo ("    LBA$sector.bin\n");
			$hash = $cdemu->save_sector ($dir_out . $track_dir . "LBA$sector.bin", $sector, $length, $hash_algos, 'cli_dump_progress');
			cli_print_hashes ($hash);
		}
	} else { // Dump unrecognized data track
		$cdemu->enable_sector_access_list();
		$sector = $cdemu->get_track_start (true);
		$length = $cdemu->get_track_length (true);
		echo ("    LBA$sector.bin\n");
		$hash = $cdemu->save_sector ($dir_out . $track_dir . "LBA$sector.bin", $sector, $length, $hash_algos, 'cli_dump_progress');
		cli_print_hashes ($hash);
	}
	return (true); // Return track descriptor
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
	if (is_array ($hash)) {
		foreach ($hash as $algo => $res)
			echo ("$pre$algo: $res\n");
		echo ("\n");
	}
}

function cli_dump_progress ($length, $pos) {
	$p = $length > 0 ? floor ($pos / $length * 100) : 0;
	echo ($cli = "      $pos / $length = $p%\r");
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
	echo ("  Example Usages:\n");
	echo ("    " . $argv[0] . " -cue \"input.cue\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -iso \"input.iso\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -bin \"Track01.bin\" -bin \"Track02.bin\" -output \"output/\"\n");
	die();
}

?>