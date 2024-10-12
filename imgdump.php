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
	if (isset ($cue))
		$cdemu->load_cue ($cue);
	
	if (isset ($iso))
		$cdemu->load_iso ($iso);
	
	if (isset ($bin)) {
		foreach ($bin as $b) {
			$cdemu->load_bin ($b);
		}
	}
	dump_image ($cdemu, $dir_out, 'sha1');
	$cdemu->eject(); // Eject Disk
}

// Dump image loaded by cdemu
function dump_image ($cdemu, $dir_out, $hash_algos = false) {
	$hash = $cdemu->hash_image ($hash_algos, 'cli_dump_progress');
	if (is_array ($hash)) {
		foreach ($hash as $algo => $res)
			echo ("    $algo: $res\n");
		echo ("\n");
	}
	for ($track = 1; $track <= $cdemu->get_track_count(); $track++) {
		$t = str_pad ($track, 2, '0', STR_PAD_LEFT);
		echo ("  Track $t\n");
		if (!$cdemu->set_track ($track))
			die ("Error: Unexpected end of image!\n");
		if ($cdemu->get_track_type() == 0) { // Audio
			$hash = $cdemu->save_track ($dir_out . "Track $t.cdda", false, $hash_algos, 'cli_dump_progress');
			if (is_array ($hash)) {
				foreach ($hash as $algo => $res)
					echo ("    $algo: $res\n");
				echo ("\n");
			}
		} else { // Data
			if (!is_dir ($dir_out . "Track $t"))
				mkdir ($dir_out . "Track $t", 0777, true);
			dump_data ($cdemu, $dir_out . "Track $t/", "../../Track %%T.cdda", $hash_algos);
		}
	}
	// TODO: Create media descriptor file
}

// Dump data track to $dir_out
function dump_data ($cdemu, $dir_out, $cdda_symlink = false, $hash_algos = false) {
	$hash = $cdemu->hash_track ($hash_algos, $cdemu->get_track(), 'cli_dump_progress');
	if (is_array ($hash)) {
		foreach ($hash as $algo => $res)
			echo ("    $algo: $res\n");
		echo ("\n");
	}
	
	$cdemu->clear_sector_access_list();
	$iso9660 = new CDEMU\ISO9660;
	$iso9660->set_cdemu ($cdemu);
	if ($iso9660->init()) { // Process ISO9660 filesystem
		if (!is_dir ($dir_out . "contents"))
			mkdir ($dir_out . "contents", 0777, true);
		$desc = array ('iso9660' => array()); // Filesystem descriptor
		$desc['iso9660']['extension'] = $iso9660->get_extension();
		$desc['iso9660']['volume_descriptor'] = desc_volume_descriptor ($iso9660->get_volume_descriptor());
		$desc['iso9660']['path_table'] = desc_path_table ($iso9660->get_path_table());
		
		if (($sa = $iso9660->get_system_area()) !== str_repeat ("\x00", strlen ($sa))) { // Check if system area is used
			$desc['iso9660']['system_area'] = true;
			file_put_contents ($dir_out . "System Area.bin", $sa);
		} else
			$desc['iso9660']['system_area'] = false;
		unset ($sa);
		
		$desc['iso9660']['content'] = array();
		$contents = $iso9660->get_content ('/', true, true); // List root recursively
		foreach ($contents as $c => $meta) { // Save contents to disk
			echo ("    $c\n");
			$desc['iso9660']['content'][$c] = desc_directory_record ($meta);
			if (substr ($c, -1, 1) == '/') { // Directory check
				if (!is_dir ($dir_out . "contents" . $c))
					mkdir ($dir_out . "contents" . $c, 0777, true);
				continue;
			}
			
			$symdepth = ($cdda_symlink !== false and $cdda_symlink[0] != "/") ? str_repeat ('../', count (explode ('/', $c)) - 2) : ''; // Amend relative symlinks
			$hash = $iso9660->save_file ($c, $dir_out . "contents" . $iso9660->format_filename ($c), ($cdda_symlink === false ? $cdda_symlink : $symdepth . $cdda_symlink), $hash_algos, 'cli_dump_progress');
			if (is_array ($hash)) {
				foreach ($hash as $algo => $res)
					echo ("      $algo: $res\n");
				echo ("\n");
			}
		}
		
		// Verify hash algos
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
		
		// Dump any unaccessed sectors within the data track
		$access = $cdemu->get_sector_access_list();
		$t_start = $cdemu->get_track_start (true);
		$t_end = $t_start + $cdemu->get_track_length (true);
		$fd = 0;
		for ($i = $t_start; $i <= $t_end; $i++) {
			if (!isset ($access[$i])) {
				if (!is_resource ($fd)) {
					$lba = str_pad ($i, strlen ($t_end), '0', STR_PAD_LEFT);
					echo ("    LBA: $lba\n");
					$fd = fopen ($dir_out . "LBA$lba.bin", "w");
					$data = $cdemu->read ($i);
					fputs ($fd, $data['sector']);
					if ($hash_algos !== false) {
						foreach ($hashes as $hash)
							hash_update ($hash, $data['sector']);
					}
				} else {
					$data = $cdemu->read();
					fputs ($fd, $data['sector']);
					if ($hash_algos !== false) {
						foreach ($hashes as $hash)
							hash_update ($hash, $data['sector']);
					}
				}
			} else if (is_resource ($fd)) {
				fclose ($fd);
				if ($hash_algos !== false) {
					foreach ($hashes as $algo => $hash)
						$hashes[$algo] = hash_final ($hash, false);
					foreach ($hashes as $algo => $res)
						echo ("      $algo: $res\n");
					echo ("\n");
				}
			}
		}
		// TODO: Create iso9660 filesystem descriptor file ($dir_out . "filesystem.desc")
	}
	// TODO: Dump binary data if not ISO9660
	return (true);
}

// TODO: Generate slim volume descriptor
//       Check for volume descriptor conformance issues
function desc_volume_descriptor ($vd) {
	$out = array ();
	return ($out);
}

// TODO: Generate slim path table
//       Check for path table conformance issues
function desc_path_table ($pt) {
	$out = array ();
	return ($out);
}

// TODO: Generate slim directory record
function desc_directory_record ($dr) {
	$out = array ();
	return ($out);
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