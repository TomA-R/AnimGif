<?php

namespace GifCreator;

/**
 * Create an animated GIF from multiple images
 *
 * @author Sybio (Clément Guillemain / @Sybio01), lunakid (@GitHub, @Gmail, @SO etc.), TomA-R
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Clément Guillemain, Szabolcs Szász
 */
class AnimGif
{
	const DEFAULT_DURATION = 10;

	/**
	 * @var string The generated (binary) image
	 */
	private $gif = 'GIF89a';

	/**
	 * @var string[] Frames string sources
	 */
	private $frameSources = array();

	/**
	 * @var int Gif loop
	 */
	private $loop = 0;

	/**
	 * @var integer Gif transparent color index
	 */
	private $transparentColor = -1;

	/**
	 * @var null|int Set this if you want the image to be resized
	 */
	private $imageWidth = null;

	/**
	 * @var null|int Set this if you want the image to be resized
	 */
	private $imageHeight = null;

	/**
	 * Create animated GIF from source images
	 *
	 * @param array $frames The source images: can be a local dir path, or an array
	 *                      of file paths, resource image variables, binary data or image URLs.
	 * @param int[]|int $durations The duration (in 1/100s) of the individual frames
	 * @param int $loop Number of loops before stopping the animation (set to 0 for an infinite loop).
	 * @param int[] $disposalMethods The disposal method specifies what happens to the current image data when you
	 *        move onto the next. We have three bits which means we can represent a number
	 *        between 0 and 7:
	 *        1 tells the decoder to leave the image in place and draw the next image on top of it
	 *        2 means that the canvas should be restored to the background color
	 *        3 means that the decoder should restore the canvas to its previous state before the current image was drawn
	 *        4-7 are yet to be defined
	 *
	 * @return $this
	 * @throws \Exception
	 */
	public function create(array $frames, $durations = 10, $loop = 0, array $disposalMethods = array())
	{
		$this->loop = ($loop > -1) ? $loop : 0;
		$frames = array_values($frames);

		// ensure keys match for both arrays
		if (is_array($durations)) {
			$durations = array_values($durations);
			if (count($frames) > count($durations)) {
				// Fill the durations array with dummy data (same as last frame) to make it have
				// at least as many values as frames. This way we always have a duration for every frame
				while (count($durations) <= count($frames)) {
					$durations[] = end($durations);
				}
			}
		} else {
			$durations = array_fill(0, count($frames) - 1, self::DEFAULT_DURATION);
		}

		// Check if $frames is a dir; get all files in ascending order if yes (else die):
		if (!is_array($frames)) {
			$frames_dir = $frames;
			if (is_dir($frames_dir)) {
				$frames = scandir($frames_dir);
				if ($frames) {
					$frames = array_filter($frames, function($x) {
						// Should these two below be selectable?
						return $x[0] != '.'; // Or: $x != "." && $x != "..";
					});

					array_walk($frames, function(&$x) use ($frames_dir) {
						$x = "{$frames_dir}/{$x}";
					});
				}
			}

			if (!is_array($frames)) {
				// $frame is expected to be a string here
				throw new \Exception(sprintf('Failed to load or invalid image (dir): "%s".', $frames_dir));
			}
		}

		foreach ($frames as $key => $frame) {

			$this->loadFrame($frame);

			for ($j = (13 + 3 * (2 << (ord($this->frameSources[$key]{10}) & 0x07))), $k = true; $k; $j++) {

				switch ($this->frameSources[$key]{$j}) {

					case '!':
						if ((substr($this->frameSources[$key], ($j + 3), 8)) == 'NETSCAPE') {
							throw new \Exception('Cannot make animation from animated GIF. (' . ($key + 1) . ' source).');
						}
						break;

					case ';':
						$k = false;
						break;
				}
			}
		}

		$this->gifAddHeader();
		for ($i = 0; $i < count($this->frameSources); $i++) {
			$this->addGifFrames($i, $durations[$i], $disposalMethods[$i]);
		}

		$this->gifAddFooter();

		return $this;
	}

	/**
	 * Write a frame's binary data into $this->frameSources
	 *
	 * @param $frame
	 * @throws \Exception
	 */
	protected function loadFrame($frame)
	{
		$frameNumber = count($this->frameSources);

		if (is_resource($frame)) {

			// in-memory image resource (hopefully)
			$frameResource = $frame;

		} elseif (is_string($frame)) {

			// file path, URL or binary data obtained by something like file_get_contents()
			if (is_readable($frame)) {

				// file path
				$bin = file_get_contents($frame);

			} elseif (preg_match('/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i', $frame)) {

				// URL
				if (ini_get('allow_url_fopen')) {
					$bin = @file_get_contents($frame);
				} else {
					throw new \Exception($frameNumber . ' Loading from URLs is disabled by PHP.');
				}

			} elseif (!ctype_print($frame)) {

				// Already binary string, like from file_get_contents()
				$bin = $frame;

			} else {

				throw new \Exception($frameNumber . printf(' Failed to load or invalid image (dir): "%s".', substr($frame, 0, 200)));

			}

			$frameResource = imagecreatefromstring($bin);
			if (!$frameResource) {
				// $frame may be binary data, not a name!
				throw new \Exception($frameNumber . printf(' Failed to load or invalid image (dir): "%s".', substr($frame, 0, 200)));
			}

		} else {
			throw new \Exception('Only image resource variables, file paths, URLs or binary bitmap data are accepted.');
		}

		if (!is_int($this->imageWidth)) {
			$this->imageWidth = imagesx($frameResource);
		}

		if (!is_int($this->imageHeight)) {
			$this->imageHeight = imagesy($frameResource);
		}

		// Now we have the binary data in $frameResource
		$newImageForFrame = imagecreatetruecolor($this->imageWidth, $this->imageHeight);

		$transparent = imagecolorallocatealpha($newImageForFrame, 0, 0, 0, 127);
		imagecopyresized($newImageForFrame, $frameResource, 0, 0, 0, 0, $this->imageWidth, $this->imageHeight, imagesx($frameResource), imagesy($frameResource));
		imagefill($newImageForFrame, 0, 0, $transparent);
		imagealphablending($newImageForFrame, false);
		imagesavealpha($newImageForFrame, true);

		ob_start();
		imagegif($newImageForFrame);
		$this->frameSources[] = ob_get_contents();
		ob_end_clean();

		if (substr($this->frameSources[$frameNumber], 0, 6) != 'GIF87a' && substr($this->frameSources[$frameNumber], 0, 6) != 'GIF89a') {
			throw new \Exception($frameNumber . ' Resource is not a GIF image.');
		}

		// Check the transparency of the first frame
		if ($frameNumber == 0) {
			$this->transparentColor = imagecolortransparent($frameResource);
		}
	}

	/**
	 * Set the created gif's width. If this isn't set, it will use the width
	 * of the frames provided
	 *
	 * @param $width
	 * @return $this Chainable
	 */
	public function setImageWidth($width)
	{
		if (is_int($width)) {
			$this->imageWidth = $width;
		}
		return $this;
	}

	/**
	 * Set the created gif's height. If this isn't set, it will use the height
	 * of the frames provided
	 *
	 * @param $height
	 * @return $this Chainable
	 */
	public function setImageHeight($height)
	{
		if (is_int($height)) {
			$this->imageHeight = $height;
		}
		return $this;
	}

	/**
	 * Get the resulting GIF image binary
	 *
	 * @return string
	 */
	public function get()
	{
		return $this->gif;
	}

	/**
	 * Save the resulting GIF to a file.
	 *
	 * @param $filename String Target file path
	 *
	 * @return int The result of file_put_contents($filename)
	 */
	public function save($filename)
	{
		return file_put_contents($filename, $this->get());
	}

	/**
	 * Clean-up the current object
	 */
	public function reset()
	{
		$this->frameSources = array();
		$this->gif = 'GIF89a'; // the GIF header
		$this->loop = 0;
		$this->transparentColor = -1;
	}

	/**
	 * Add the header gif string in its source
	 * @see http://giflib.sourceforge.net/whatsinagif/bits_and_bytes.html#header_block
	 *
	 * Looks something like:
	 *   0 : 47 49 46 38 39 61 64 01 68 01 e7 00 00 04 02 04 [GIF89ad.h.......]
	 *  10 : 9c 82 1c dc c2 0c 7c 1e 24 c4 a6 14 4c 3e 1c c4 [......|.$...L>..]
	 *  20 : c6 c4 cc b2 14 84 82 84 7c 62 34 fc d2 0c ac 92 [........|b4.....]
	 *  30 : 14 74 5a 2c e4 b2 44 ec c6 14 c4 9e 2c 3c 1e 24 [.tZ,..D.....,<.$]
	 *  40 : 5c 4e 1c 8c 76 1c f4 c2 3c b4 8e 34 b4 92 2c a4 [\N..v...<..4..,.]
	 *  50 : 82 34 bc 1e 24 ec ea ec dc b6 24 cc aa 2c 4c 4a [.4..$.....$..,LJ]
	 *  60 : 4c 64 66 64 e4 ba 34 ec ca 0c 64 1e 24 54 46 24 [Ldfd..4...d.$TF$]
	 *  70 : 7c 6e 1c fc da 04 8c 7a 14 24 1e 1c c4 aa 0c dc [|n.....z.$......]
	 *  80 : be 0c ac aa ac f4 d2 14 3c 32 24 54 52 54 bc 9a [........<2$TRT..]
	 *  90 : 34 f4 ba 44 a4 8a 24 a4 1e 24 b4 9a 14 74 62 14 [4..D..$..$...tb.]
	 *  A0 : cc a6 34 f4 ca 2c a4 8a 2c e4 1e 24 f4 f6 f4 f4 [..4..,..,..$....]
	 *  B0 : ca 24 8c 6e 34 2c 2a 1c d4 d6 d4 94 96 94 cc 9e [.$.n4,*.........]
	 *  C0 : 3c 64 56 1c a4 82 3c dc ae 34 e4 ba 3c 54 46 2c [<dV...<..4..<TF,]
	 *  D0 : 7c 6a 24 9c 7e 2c 9c 82 24 e4 c2 24 8c 1e 24 c4 [|j$.~,..$..$..$.]
	 *  E0 : a2 24 4c 42 1c d4 b2 14 fc d6 04 74 5e 24 ec c2 [.$LB.......t^$..]
	 *  F0 : 24 4c 1e 24 64 52 24 8c 72 2c f4 c6 34 bc 96 3c [$L.$dR$.r,..4..<]
	 *  100 : a4 86 2c cc 1e 24 dc b2 34 d4 aa 44 74 72 74 e4 [..,..$..4..Dtrt.]
	 *  110 : be 2c 54 4a 1c 94 7a 34 cc aa 24 dc ba 1c fc d2 [.,TJ..z4..$.....]
	 *  120 : 14 3c 3a 3c bc 9e 2c b4 9a 1c fc fe fc f4 ce 1c [.<:<..,.........]
	 *  130 : 34 2e 2c cc a2 34 9c 86 14 c4 a6 1c 4c 3e 24 cc [4.,..4......L>$.]
	 *  140 : c6 c4 94 8e 8c 7c 66 2c b4 96 24 e4 b6 3c ec c6 [.....|f,..$..<..]
	 *  150 : 1c 8c 76 24 b4 92 34 f4 f2 f4 dc b6 2c 6c 6a 6c [..v$..4.....,ljl]
	 *  160 : ec ca 14 6c 1e 24 84 6e 14 8c 7a 1c 2c 1e 1c dc [...l.$.n..z.,...]
	 *  170 : be 14 bc ba bc f4 d2 1c 3c 36 1c 5c 56 54 f4 be [........<6.\VT..]
	 *  180 : 44 a4 8e 1c ac 1e 24 b4 9e 0c 74 62 1c f4 ca 34 [D.....$...tb...4]
	 *  190 : e4 e2 e4 9c 9e 9c fc fa fc ec 1e 24 94 1e 24 54 [...........$..$T]
	 *  1A0 : 1e 24 d4 1e 24 d4 b6 0c d4 ae 3c cc ae 1c 2c 2a [.$..$.....<...,*]
	 *  1B0 : 24 7c 6a 2c e4 c2 2c fc d6 0c 74 5e 2c 64 52 2c [$|j,..,...t^,dR,]
	 *  1C0 : f4 c6 3c a4 86 34 e4 be 34 54 4a 24 bc 9e 34 f4 [..<..4..4TJ$..4.]
	 *  1D0 : ce 24 cc a2 3c 00 00 00 00 00 00 00 00 00 00 00 [.$..<...........]
	 *  1E0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  1F0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  200 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  210 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  220 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  230 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  240 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  250 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  260 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  270 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  280 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  290 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  2A0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  2B0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  2C0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  2D0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  2E0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  2F0 : 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
	 *  300 : 00 00 00 00 00 00 00 00 00 00 00 00 00 21 ff 0b [.............!..]
	 *  310 : 4e 45 54 53 43 41 50 45 32 2e 30 03 01 00 00 00 [NETSCAPE2.0.....]
	 *
	 * - Header
	 * The first 3 bytes (47 49 46) are called the signature. These should always be "GIF" (ie 47="G", 49="I", 46="F").
	 * The next 3 bytes (38 39 61) specify the version of the specification that was used to encode the image (e.g. 89a)
	 *
	 * - Logical Screen Descriptor
	 * The next 7 bytes (64 01 68 01 e7 00 00) are the logical screen descriptor which tells the decoder how much room
	 *     this image will take up. It starts with the canvas width and canvas height. These value can be found in the
	 *     first two pairs of two bytes each - with the least significant bit first (64 01 <- width =
	 *     0000000101000000 = 256+63 = 320, 68 01 <- height = 0000000101000100 = 256+)
	 *
	 * - [Optional] Global colour table lists the colours used in the image (can someone confirm?)
	 *
	 * - Application Extension Block (21 ff 0b 4e 45 54 53 43 41 50 45 32 2e 30) In this case it's
	 * NETSCAPE2.0 followed by actual application data (03 <- three bytes to follow, 01 <- always the same,
	 * 00 <- how often to repeat; 0 is forever, 00 <-- ???, 00 <-- block terminator)
	 */
	private function gifAddHeader()
	{
		// If the 10th byte of the first frame has the "128" (0x80 or 49) bit on ... add a header
		// for some reason? Otherwise what happens? I couldn't figure it out. Maybe it's to detect that
		// it's actually a gif?
		if (ord($this->frameSources[0]{10}) & 0x80) {

			$colorMap = 3 * (2 << (ord($this->frameSources[0]{10}) & 0x07));

			$this->gif .= substr($this->frameSources[0], 6, 7);
			$this->gif .= substr($this->frameSources[0], 13, $colorMap);
			$this->gif .= "!\377\13NETSCAPE2.0\3\1" . self::word2bin($this->loop) . "\0";
		}
	}

	/**
	 * Add the frame sources to the GIF binary string
	 *
	 * @param int $frameNumber
	 * @param int $duration
	 * @param int $disposalMethod
	 */
	protected function addGifFrames($frameNumber, $duration, $disposalMethod)
	{
		// @todo: Figure out how this works and write some comments/docs

		// Something to do with finding the start and end positions of image data of the current frame?
		$frameDataStartPosition = 13 + 3 * (2 << (ord($this->frameSources[$frameNumber]{10}) & 0x07));
		$frameDataEndPosition = strlen($this->frameSources[$frameNumber]) - $frameDataStartPosition - 1;
		$frameData = substr($this->frameSources[$frameNumber], $frameDataStartPosition, $frameDataEndPosition);

		// I'm not sure how this works..
		$firstFrameLength = 2 << (ord($this->frameSources[0]{10}) & 0x07);
		$currentFrameLength = 2 << (ord($this->frameSources[$frameNumber]{10}) & 0x07);

		$globalColourTable = substr($this->frameSources[0], 13, 3 * (2 << (ord($this->frameSources[0]{10}) & 0x07)));
		$currentFrameColourTable = substr($this->frameSources[$frameNumber], 13, 3 * (2 << (ord($this->frameSources[$frameNumber]{10}) & 0x07)));

		// Packed field bits:
		// 1, 2 = delay time in ticks (in hundredths of seconds) to wait before moving on to the next screen
		// 3 = user input flag. When set to 1 the decoder will wait for some sort of "input" from the person viewing the image before moving on to the next scene
		// 4, 5, 6 = Disposal method bits:
		//           000 (0) = no disposal method, not animated
		//           001 (1) = leave image in place, draw over it
		//           010 (2) = canvas should be restored to the background color
		//           011 (3) = decoder should restore the canvas to its previous state before the current image is drawn
		//           Other values are not yet defined
		// 7, 8 = reserved for future use (so zero at the moment)
		// @see http://giflib.sourceforge.net/whatsinagif/animation_and_transparency.html#animation
		$animationExtensionBlockByte = "!\xF9\x04" . chr(($disposalMethod << 2) + 1) . self::word2bin($duration) . "\x0\x0";

		// It seems like the following is the case:
		// Each image begins with an image descriptor block. That block is exactly 10 bytes long.
		// The first byte is the image separator. Every image descriptor begins with the value 2C (that's ","). The
		// next 8 bytes represent the location and size of the following image.

		// OK, I *think* that there could be an optional byte ($frameData{0} to $frameData{7}) but I'm not sure
		// what it is. Seems to start with GIF Extension code (!). We ignore it.
		if ($frameData{0} === '!') {
			$frameData = substr($frameData, 8);
		}

		// Seems like
		// $frameData{1} and $frameData{2} are image left positioning (little endian, so concat bits from $frameData{2} and $frameData{1} to get 16 bits and work out the value from there
		// $frameData{3} and $frameData{4} are image top positioning (little endian, so concat bits from $frameData{4} and $frameData{3} to get 16 bits and work out the value from there
		// $frameData{5} and $frameData{6} are height (little endian, so concat bits from $frameData{6} and $frameData{5} to get 16 bits and work out the value from there
		// $frameData{7} and $frameData{8} are width (little endian, so concat bits from $frameData{8} and $frameData{7} to get 16 bits and work out the value from there
		// $frameData{9} is a packed field and includes bits that indicate:
		// $frameData{9} ^ 128 = Local colour table flag: Setting this flag to 1 allows you to specify that the image data that follows uses a different color table than the global color table
		// $frameData{9} ^ 64 = Interlace flag: Interlacing changes the way images are rendered onto the screen in a way that may reduce annoying visual flicker
		// $frameData{8} ^ 32 = Sort flag: If the values is 1, then the colors in the global color table are sorted in order of "decreasing importance,"
		// $frameData{9} ^ 16 = Reserved for future use
		// $frameData{9} ^ 8 = Reserved for future use
		// $frameData{9} ^ 4 and ^ 2 and ^ 1 = Size of local colour table
		// @see http://giflib.sourceforge.net/whatsinagif/bits_and_bytes.html
		// @see https://www.ffmpeg.org/doxygen/2.1/libavformat_2gif_8c_source.html
		$frameMetaData = substr($frameData, 0, 10);
		$frameImageData = substr($frameData, 10, strlen($frameData) - 10);

		if (ord($this->frameSources[$frameNumber]{10}) & 0x80 && $frameNumber > 0) {

			if ($firstFrameLength == $currentFrameLength) {

				$this->gif .= $animationExtensionBlockByte;

				if ($this->gifBlockCompare($globalColourTable, $currentFrameColourTable, $firstFrameLength)) {

					$this->gif .= $frameMetaData . $frameImageData;

				} else {

					// I imagine this is rewriting the
					$byte = ord($frameMetaData{9});
					$byte |= 0x80;
					$byte &= 0xF8;
					$byte |= (ord($this->frameSources[0]{10}) & 0x07);
					$frameMetaData{9} = chr($byte);
					$this->gif .= $frameMetaData . $currentFrameColourTable . $frameImageData;
				}

			} else {

				$byte = ord($frameMetaData{9});
				$byte |= 0x80;
				$byte &= 0xF8;
				$byte |= (ord($this->frameSources[$frameNumber]{10}) & 0x07);
				$frameMetaData{9} = chr($byte);
				$this->gif .= $animationExtensionBlockByte . $frameMetaData . $currentFrameColourTable . $frameImageData;

			}

		} else {

			$this->gif .= $animationExtensionBlockByte . $frameMetaData . $frameImageData;

		}
	}


	/**
	 * Add the gif string footer char
	 */
	protected function gifAddFooter()
	{
		$this->gif .= ';';
	}

	/**
	 * Compare two block and return the version <- WHAT?! What does that even mean?
	 *
	 * @param string $globalBlock
	 * @param string $localBlock
	 * @param int $length
	 *
	 * @return int
	 */
	protected function gifBlockCompare($globalBlock, $localBlock, $length)
	{
		for ($i = 0; $i < $length; $i++) {

			if ($globalBlock[3 * $i + 0] != $localBlock[3 * $i + 0] ||
				$globalBlock[3 * $i + 1] != $localBlock[3 * $i + 1] ||
				$globalBlock[3 * $i + 2] != $localBlock[3 * $i + 2]) {

				return 0;
			}
		}

		return 1;
	}

	/**
	 * Convert an integer to 2-byte little-endian binary data
	 *
	 * @param integer $word Number to encode
	 *
	 * @return string of 2 bytes representing @word as binary data
	 */
	private static function word2bin($word)
	{
		return chr($word & 0xFF) . chr(($word >> 8) & 0xFF);
	}
}
