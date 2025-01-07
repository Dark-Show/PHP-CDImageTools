#!/usr/bin/env php
<?php

// Title: CD-ROM Image Dumper
// Description: Dumps CD images to directories
// Author: Greg Michalik
const VERSION = '0.1';

include ('include/cdrom/cdemu.const.php');
include ('include/cdrom/cdemu.php');
include ('include/cdrom/cdemu.iso9660.php');

cli_process_argv ($argv);

function cli_process_argv ($argv) {
	echo ("CD-ROM Image Dumper v" . VERSION . "\n");
	if (count ($argv) == 1)
		cli_display_help ($argv);

	$dir_out = "output/";
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
	dump_image ($cdemu, $dir_out, ['crc32b', 'sha256', 'md5']);
	$cdemu->eject(); // Eject Disk
}

// Dump image loaded by cdemu
function dump_image ($cdemu, $dir_out, $hash_algos = false) {
	$hash = $cdemu->hash_image ($hash_algos, 'cli_dump_progress'); // Hash entire image
	if (is_array ($hash) and isset ($hash['full'])) {
		foreach ($hash['full'] as $algo => $res)
			echo ("  $algo: $res\n");
		echo ("\n");
	}
	$cdemu->clear_sector_access_list();
	
	// Dump each track
	for ($track = 1; $track <= $cdemu->get_track_count(); $track++) {
		$t = str_pad ($track, 2, '0', STR_PAD_LEFT);
		echo ("  Track $t\n");
		if (is_array ($hash['track'][$track])) {
			foreach ($hash['track'][$track] as $algo => $res)
				echo ("    $algo: $res\n");
			echo ("\n");
		}
		if (!$cdemu->set_track ($track))
			die ("Error: Unexpected end of image!\n");
		if ($cdemu->get_track_type() == 0) { // Audio
			dump_audio ($cdemu, $dir_out, "Track $t.cdda");
		} else { // Data
			if (!is_dir ($dir_out . "Track $t"))
				mkdir ($dir_out . "Track $t", 0777, true);
			dump_data ($cdemu, $dir_out, "Track $t/", "../../Track %%T.cdda", $hash_algos); // Returns track descriptor
		}
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
function dump_data ($cdemu, $dir_out, $track_dir, $cdda_symlink = false, $hash_algos = false) {
	$iso9660 = new CDEMU\ISO9660;
	$iso9660->set_cdemu ($cdemu);
	if ($iso9660->init ($cdemu->get_track_start (true))) { // Process ISO9660 filesystem
		if (!is_dir ($dir_out . $track_dir . "contents"))
			mkdir ($dir_out . $track_dir . "contents", 0777, true);
		
		echo ("    System Area\n");
		if (($sa = $iso9660->get_system_area()) != str_repeat ("\x00", strlen ($sa))) { // Check if system area is used
			$hash = $iso9660->save_system_area ($dir_out . $track_dir . 'System Area.bin', $hash_algos);
			if (is_array ($hash)) {
				foreach ($hash as $algo => $res)
					echo ("      $algo: $res\n");
				echo ("\n");
			}
		}
		unset ($sa);
		
		$contents = $iso9660->get_content ('/', true, true); // List root recursively
		foreach ($contents as $c => $meta) { // Save contents to disk
			echo ("    $c\n");
			if (substr ($c, -1, 1) == '/') { // Directory
				if (!is_dir ($dir_out . $track_dir . "contents" . $c))
					mkdir ($dir_out . $track_dir . "contents" . $c, 0777, true);
				continue;
			}
			// File
			$symdepth = ($cdda_symlink !== false and $cdda_symlink[0] != "/") ? str_repeat ('../', count (explode ('/', $c)) - 2) : ''; // Amend relative symlinks
			$hash = $iso9660->save_file ($c, $dir_out . $track_dir . "contents" . $iso9660->format_fileid ($c), ($cdda_symlink === false ? $cdda_symlink : $symdepth . $cdda_symlink), $hash_algos, 'cli_dump_progress');
			if (is_array ($hash)) {
				foreach ($hash as $algo => $res)
					echo ("      $algo: $res\n");
				echo ("\n");
			}
		}
		
		// Dump any unaccessed sectors within the data track
		$t_start = $cdemu->get_track_start (true);
		$t_end = $t_start + $cdemu->get_track_length (true) - 1;
		foreach ($cdemu->get_sector_unaccessed_list ($t_start, $t_end) as $sector => $length) {
			echo ("    LBA: $sector\n");
			$hash = $cdemu->save_sector ($dir_out . $track_dir . "LBA$sector.bin", $sector, $length, $hash_algos, 'cli_dump_progress');
			if (is_array ($hash)) {
				foreach ($hash as $algo => $res)
					echo ("      $algo: $res\n");
				echo ("\n");
			}
		}
	} else {
		// Dump unrecognized data track
		$sector = $cdemu->get_track_start (true);
		$length = $cdemu->get_track_length (true);
		echo ("    LBA: $sector\n");
		$hash = $cdemu->save_sector ($dir_out . $track_dir . "LBA$sector.bin", $sector, $length, $hash_algos, 'cli_dump_progress');
		if (is_array ($hash)) {
			foreach ($hash as $algo => $res)
				echo ("      $algo: $res\n");
			echo ("\n");
		}
	}
	return (true); // Return track descriptor
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
	echo ("    -output \"PATH/\"    Output directory\n\n");
	echo ("  Example Usages:\n");
	echo ("    " . $argv[0] . " -cue \"input.cue\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -iso \"input.iso\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -bin \"Track01.bin\" -bin \"Track02.bin\" -output \"output/\"\n");
	die();
}

?>