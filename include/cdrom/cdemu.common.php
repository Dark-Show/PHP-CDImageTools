<?php

// Validate support of hashes
function cdemu_hash_validate ($hash_algos) {
	if ($hash_algos == false)
		return (false);
	$r_hash = array();
	$s_hash = hash_algos();
	if (is_string ($hash_algos))
		$hash_algos = array ($hash_algos);
	foreach ($hash_algos as $algo) {
		foreach ($s_hash as $sa) {
			if ($sa == $algo)
				$r_hash[] = $sa;
		}
	}
	if (count ($s_hash) == 0)
		return (false);
	return ($r_hash);
}

?>