<?php

function serveFilePartial($fileName, $fileTitle = null, $contentType = 'application/octet-stream') {
	checkFile($fileName);
	if (empty($fileTitle)) $fileTitle = basename($fileName);
	
	$fileSize = filesize($fileName);
	if (isset($_SERVER['HTTP_RANGE']) && (!$result = parseRangeHeader($fileSize))) {
		$byteOffset  = $result[0];
		$finishBytes = $result[1];
	} else {
		// Default to send entire file
        $byteOffset  = 0;
        $finishBytes = $fileSize - 1;	
	}

	$byteRange = $finishBytes - $byteOffset + 1;

	setHeader($contentType, $fileTitle, $byteRange);
	
	rangeOutput(openFile($fileName, $byteOffset), $byteRange);
	
	exit();
}

function checkFile($fileName) {
	if (!file_exists($fileName))
		throw new Exception(sprintf('File not found: %s', $fileName));

	if (!is_readable($fileName))
		throw new Exception(sprintf('File not readable: %s', $fileName));
}

function openFile($fileName, $byteOffset) {
	if (!$handle = fopen($fileName, 'r'))
		throw new Exception(sprintf("Could not get handle for file %s", $fileName));

	if (fseek($handle, $byteOffset, SEEK_SET) == -1)
		throw new Exception(sprintf("Could not seek to byte offset %d", $byteOffset));
	
	return $handle;
}

function setHeader($contentType, $fileTitle, $byteRange) {
	// Remove headers that might unnecessarily clutter up the output
	header_remove('Cache-Control');
	header_remove('Pragma');
	
    header('Accept-Ranges: bytes', true);
	header(sprintf('Content-Type: %s', $contentType), true);
    header(sprintf('Content-Disposition: attachment; filename="%s"', $fileTitle));
	header(sprintf('Content-Length: %d', $byteRange));
	header(sprintf('Expires: %s', date('D, d M Y H:i:s', time() + 60*60*24*7) . ' GMT'));
}

function parseRangeHeader($fileSize) {
	// Parse Content-Range header for byte offsets, looks like "bytes=11525-" OR "bytes=11525-12451"
	if (preg_match('/bytes=(\d+)-(\d+)?/i', $_SERVER['HTTP_RANGE'], $matches)) {
		// Offset signifies where we should begin to read the file
		$byteOffset = (int) $matches[1];


		// Length is for how long we should read the file according to the browser, and can never go beyond the file size
		if (isset($matches[2])) {
			$finishBytes = (int) $matches[2];
		} else {
			$finishBytes = $fileSize - 1;
		}
	
		header("HTTP/1.1 206 Partial content");
		header(sprintf('Content-Range: bytes %d-%d/%d', $byteOffset, $finishBytes, $fileSize));  // Decrease by 1 on byte-length since this definition is zero-based index of bytes being sent
		
		return array($byteOffset, $finishBytes);
	} else {
		return false;
	}
}

function rangeOutput($handle, $byteRange) {
	$bufferSize = 512 * 16; // Just a reasonable buffer size
	$bytePool = $byteRange; // Contains how much is left to read of the byteRange

	while ($bytePool > 0) {
		$chunkSizeRequested = min($bufferSize, $bytePool); // How many bytes we request on this iteration

		// Try readin $chunkSizeRequested bytes from $handle and put data in $buffer
		$buffer = fread($handle, $chunkSizeRequested);

		// Store how many bytes were actually read
		$chunkSizeActual = strlen($buffer);

		// If we didn't get any bytes that means something unexpected has happened since $bytePool should be zero already
		if ($chunkSizeActual == 0) {
			// For production servers this should go in your php error log, since it will break the output
			trigger_error('Chunksize became 0', E_USER_WARNING);
			break;
		}

		// Decrease byte pool with amount of bytes that were read during this iteration
		$bytePool -= $chunkSizeActual;

		// Write the buffer to output
		print $buffer;

		// Try to output the data to the client immediately
		ob_flush();
		flush();
	}	
}
