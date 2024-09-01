#!/usr/bin/env php
<?php

// Title: CD-ROM Image Dumper
// Description: Dumps CD images to directories
// Author: Greg Michalik
const VERSION = '0.1';

include ('include/cdrom/cdemu.php');
include ('include/cdrom/iso9660.php');
//include ('./include/binary.php'); // DEBUG FUNCTIONS

cli_process_argv ($argv);

function cli_process_argv ($argv) {
	if (count ($argv) == 1)
		cli_display_help ($argv);
	
	$remove_version = false;
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
			case '-strip_version':
				$remove_version = true;
				break;
			default:
				cli_display_help ($argv);
		}
	}
	echo ("CD-ROM Image Dumper v" . VERSION . "\n");
	$cdemu = new CDEMU;
	if (isset ($cue))
		$cdemu->load_cue ($cue);
	
	if (isset ($iso))
		$cdemu->load_iso ($iso);
	
	if (isset ($bin)) {
		foreach ($bin as $b) {
			$cdemu->load_bin ($b);
		}
	}
	dump_image ($cdemu, $dir_out, $remove_version);
	$cdemu->eject(); // Eject Disk
}

// Dump image loaded by cdemu
function dump_image ($cdemu, $dir_out, $remove_version = false) {
	for ($track = 1; $track <= $cdemu->get_track_count(); $track++) {
		$t = str_pad ($track, 2, '0', STR_PAD_LEFT);
		echo ("  Track $t\n");
		if (!$cdemu->set_track ($track))
			die ("Error: Unexpected end of image!\n");
		if ($cdemu->get_track_type() == 1) { // Data
			if (!is_dir ($dir_out . "Track $t"))
				mkdir ($dir_out . "Track $t", 0777, true);
			dump_data ($cdemu, $dir_out . "Track $t/", "../../Track %%T.cdda", $remove_version);
		} else // Audio
			dump_audio ($cdemu, $dir_out . "Track $t.cdda");
	}
}

// Dump audio track to $file_out
function dump_audio ($cdemu, $file_out) {
	$fp = fopen ($file_out, 'w');
	$s_len = $cdemu->get_track_length (true);
	for ($s_cur = 0; $s_cur < $s_len; $s_cur++){
    	$sector = $cdemu->read();
		if (isset ($sector['data']))
			fputs ($fp, $sector['data']);
		cli_dump_progress ($s_len * 2352, $cdemu->get_track_time (true) * 2352);
	}
	fclose ($fp);
	return (true);
}

// Dump data track to $dir_out
function dump_data ($cdemu, $dir_out, $cdda_symlink = false, $remove_version = false) {
	$iso9660 = new CDEMU\ISO9660;
	$iso9660->set_cdemu ($cdemu);
	if ($iso9660->init()) { // Process ISO9660 filesystem
		if (!is_dir ($dir_out . "contents"))
			mkdir ($dir_out . "contents", 0777, true);
		$iso9660->save_system_area ($dir_out . "system_area.bin");
		$contents = $iso9660->list_contents(); // List contents recursively
		foreach ($contents as $c) { // Save contents to disk
			echo ("    $c\n");
			if (substr ($c, -1, 1) == '/') { // Directory check
				if (!is_dir ($dir_out . "contents" . $c))
					mkdir ($dir_out . "contents" . $c, 0777, true);
				continue;
			}
			$f = $remove_version ? $iso9660->format_filename ($c) : $c; // Remove version from filename
			$symdepth = ($cdda_symlink != false and $cdda_symlink[0] != "/") ? str_repeat ('../', count (explode ('/', $c)) - 2) : ''; // Amend relative symlinks
			$iso9660->save_file ($c, $dir_out . "contents" . $f, ($cdda_symlink === false ? $cdda_symlink : $symdepth . $cdda_symlink), 'cli_dump_progress');
		}
		
		// Dump any unaccessed sectors within the data track
		$access = $cdemu->get_sector_access_list();
		$t_start = $cdemu->get_track_start (true);
		$t_end = $t_start + $cdemu->get_track_length (true);
		$fd = 0;
		for ($i = $t_start; $i < $t_end; $i++) {
			if (!isset ($access[$i])) {
				if (!is_resource ($fd)) {
					$lba = str_pad ($i, strlen ($t_end), '0', STR_PAD_LEFT);
					echo ("LBA: $lba\n");
					$fd = fopen ($dir_out . "LBA$lba.bin", "w");
					$data = $cdemu->read ($i);
					fputs ($fd, $data['sector']);
				} else {
					$data = $cdemu->read();
					fputs ($fd, $data['sector']);
				}
			} else if (is_resource ($fd))
				fclose ($fd);
		}
	}
	// TODO: Dump binary data if not ISO9660
	return (true);
}

function cli_dump_progress ($length, $pos) {
	$p = $length > 0 ? floor ($pos / $length * 100) : 0;
	echo ($cli = "      $pos / $length = $p%\r");
	if ($p == 100)
		echo (str_repeat (' ', strlen ($cli)) . "\r");
}

function cli_display_help ($argv) {
	echo ("CD-ROM Image Dumper v" . VERSION . "\n");
	echo ("  Arguments:\n");
	echo ("    -cue \"FILE.CUE\"    Input CUE file\n");
	echo ("    -iso \"FILE.ISO\"    Input ISO file\n");
	echo ("    -bin \"FILE.BIN\"    Input BIN file\n");
	echo ("    -output \"PATH/\"    Output directory\n");
	echo ("    -strip_version     Remove file version from filename\n\n");
	echo ("  Example Usages:\n");
	echo ("    " . $argv[0] . " -cue \"input.cue\" -output \"output/\"\n");
	echo ("    " . $argv[0] . " -iso \"input.iso\" -output \"output/\" -strip_version\n");
	echo ("    " . $argv[0] . " -bin \"Track01.bin\" -bin \"Track02.bin\" -output \"output/\"\n");
	die();
}

?>