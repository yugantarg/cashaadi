/**
 * csmCropper — a small pan + zoom image cropper.
 *
 * Owner: "allow horizontal or vertical scroll so that the user can effectively
 * decide how much to crop. Also allow zoom in. Both these together render a crop
 * function." So: drag to reposition, wheel / pinch / slider to zoom, and the
 * fixed-aspect frame is what gets exported.
 *
 * window.csmCropper( file, opts ) -> Promise<{ node, master, rect, export, destroy }>
 *   opts.aspect     target width/height (default 4/5 portrait)
 *   opts.outW       cropped export width (height derived from aspect)
 *   opts.masterMax  longest edge of the master, default 2000
 *   master()     -> Promise<Blob>  the WHOLE photo, never upscaled
 *   rect()       -> {x,y,w,h}      the crop, as fractions of the source
 *   export()     -> Promise<Blob>  the cropped region alone (legacy callers)
 *
 * NEVER DISCARD THE MEMBER'S PHOTO. Owner's rule: "never downscale a photo the
 * user uploads ... I hope you retain the full photo not just the cropped
 * portion. the cropped portion is the part that's shown to other users. but the
 * owner should be able to adjust the crop later."
 *
 * This used to export the cropped canvas alone, at a fixed 1080px, and that was
 * all that ever reached the server — so the original was destroyed in the
 * BROWSER, before upload, and re-cropping later was impossible for anyone. Now
 * the whole image is uploaded as the master and the crop travels beside it as a
 * rectangle.
 *
 * The rectangle is NORMALISED — fractions of the source, not pixels — so it
 * stays correct when the master is capped at 2000px on the way out, and stays
 * correct again against any future derivative. Pixel coordinates would have
 * silently referred to the wrong region the moment either size changed.
 *
 * The image is always kept covering the frame, so the crop can never include
 * empty edges. Everything is pointer-events based, so one code path serves mouse,
 * touch and pen; pinch is handled by tracking two active pointers.
 */
( function () {
	'use strict';

	function clamp( v, lo, hi ) { return v < lo ? lo : ( v > hi ? hi : v ); }

	window.csmCropper = function ( file, opts ) {
		opts = opts || {};
		var aspect = opts.aspect || ( 4 / 5 );
		var outW   = opts.outW || 1080;
		var outH   = Math.round( outW / aspect );
		var maxEdge = opts.masterMax || 2000;

		return new Promise( function ( resolve, reject ) {
			var img = new Image();
			img.onerror = function () { reject( new Error( 'load' ) ); };
			img.onload = function () {
				resolve( build( img ) );
			};
			img.src = URL.createObjectURL( file );

			function build( image ) {
				var iw = image.naturalWidth, ih = image.naturalHeight;

				var node = document.createElement( 'div' );
				node.className = 'csm-crop';

				var frame = document.createElement( 'div' );
				frame.className = 'csm-crop-frame';
				frame.style.aspectRatio = aspect + '';
				node.appendChild( frame );

				var canvasImg = document.createElement( 'img' );
				canvasImg.className = 'csm-crop-img';
				canvasImg.src = image.src;
				canvasImg.draggable = false;
				frame.appendChild( canvasImg );

				var hint = document.createElement( 'p' );
				hint.className = 'csm-crop-hint';
				hint.textContent = 'Drag to reposition · pinch or use the slider to zoom';
				node.appendChild( hint );

				var slider = document.createElement( 'input' );
				slider.type = 'range';
				slider.className = 'csm-crop-zoom';
				slider.min = '1'; slider.max = '3'; slider.step = '0.01'; slider.value = '1';
				node.appendChild( slider );

				// Frame pixel size is known only after layout; measure lazily.
				var state = { scale: 1, x: 0, y: 0, base: 1, fw: 0, fh: 0 };

				function measure() {
					var r = frame.getBoundingClientRect();
					state.fw = r.width; state.fh = r.height;
					// base scale: cover the frame
					state.base = Math.max( state.fw / iw, state.fh / ih );
				}

				function apply() {
					var s = state.base * state.scale;
					var dw = iw * s, dh = ih * s;
					// keep the image covering the frame
					state.x = clamp( state.x, state.fw - dw, 0 );
					state.y = clamp( state.y, state.fh - dh, 0 );
					canvasImg.style.width  = dw + 'px';
					canvasImg.style.height = dh + 'px';
					canvasImg.style.transform = 'translate(' + state.x + 'px,' + state.y + 'px)';
				}

				function reset() {
					measure();
					state.scale = 1;
					var s = state.base;
					state.x = ( state.fw - iw * s ) / 2;
					state.y = ( state.fh - ih * s ) / 2;
					apply();
				}

				// ---- pan (pointer) + pinch (two pointers) --------------------
				var pointers = {};
				var pinchStart = null;

				function onDown( e ) {
					frame.setPointerCapture && frame.setPointerCapture( e.pointerId );
					pointers[ e.pointerId ] = { x: e.clientX, y: e.clientY };
					if ( Object.keys( pointers ).length === 2 ) {
						var pts = keysPts();
						pinchStart = {
							dist: dist( pts[0], pts[1] ),
							scale: state.scale
						};
					}
				}
				function onMove( e ) {
					if ( ! pointers[ e.pointerId ] ) { return; }
					var prev = pointers[ e.pointerId ];
					pointers[ e.pointerId ] = { x: e.clientX, y: e.clientY };
					var ids = Object.keys( pointers );
					if ( ids.length === 2 && pinchStart ) {
						var pts = keysPts();
						var d = dist( pts[0], pts[1] );
						state.scale = clamp( pinchStart.scale * ( d / pinchStart.dist ), 1, 3 );
						slider.value = state.scale + '';
						apply();
						return;
					}
					state.x += e.clientX - prev.x;
					state.y += e.clientY - prev.y;
					apply();
				}
				function onUp( e ) {
					delete pointers[ e.pointerId ];
					if ( Object.keys( pointers ).length < 2 ) { pinchStart = null; }
				}
				function keysPts() { return Object.keys( pointers ).map( function ( k ) { return pointers[ k ]; } ); }
				function dist( a, b ) { return Math.hypot( a.x - b.x, a.y - b.y ); }

				frame.addEventListener( 'pointerdown', onDown );
				frame.addEventListener( 'pointermove', onMove );
				frame.addEventListener( 'pointerup', onUp );
				frame.addEventListener( 'pointercancel', onUp );

				// wheel zoom (desktop)
				frame.addEventListener( 'wheel', function ( e ) {
					e.preventDefault();
					state.scale = clamp( state.scale * ( e.deltaY < 0 ? 1.06 : 0.94 ), 1, 3 );
					slider.value = state.scale + '';
					apply();
				}, { passive: false } );

				slider.addEventListener( 'input', function () {
					state.scale = clamp( parseFloat( slider.value ) || 1, 1, 3 );
					apply();
				} );

				// Re-measure once attached/laid out.
				requestAnimationFrame( reset );
				window.addEventListener( 'resize', reset );

				function exportBlob() {
					return new Promise( function ( res ) {
						var s = state.base * state.scale;
						// Map the frame's top-left onto the source image.
						var sx = -state.x / s;
						var sy = -state.y / s;
						var sw = state.fw / s;
						var sh = state.fh / s;

						var cv = document.createElement( 'canvas' );
						cv.width = outW; cv.height = outH;
						var ctx = cv.getContext( '2d' );
						ctx.imageSmoothingEnabled = true;
						ctx.imageSmoothingQuality = 'high';
						ctx.drawImage( image, sx, sy, sw, sh, 0, 0, outW, outH );
						cv.toBlob( function ( b ) { res( b ); }, 'image/jpeg', 0.92 );
					} );
				}

				/**
				 * The crop, in fractions of the source image.
				 *
				 * Clamped to 0..1: the frame is always covered by the image, so
				 * this should already be in range, but a rounding error that put
				 * x+w over 1 would make the server crop outside the file.
				 */
				function rect() {
					var s = state.base * state.scale;
					var x = clamp( ( -state.x / s ) / iw, 0, 1 );
					var y = clamp( ( -state.y / s ) / ih, 0, 1 );
					return {
						x: x,
						y: y,
						w: clamp( ( state.fw / s ) / iw, 0, 1 - x ),
						h: clamp( ( state.fh / s ) / ih, 0, 1 - y )
					};
				}

				/**
				 * The whole photo, downscaled ONLY if it is bigger than maxEdge.
				 *
				 * Never upscaled — that was the old export's real sin: it wrote
				 * 1080px whatever the source held, so a 300px pick became a
				 * 1080px file that looked sharp to every check we had and soft to
				 * every human. A file smaller than the cap is passed through
				 * untouched, original bytes and all.
				 */
				function master() {
					return new Promise( function ( res ) {
						var longest = Math.max( iw, ih );
						if ( longest <= maxEdge ) {
							return res( file );   // as uploaded, not re-encoded
						}
						var k  = maxEdge / longest;
						var cv = document.createElement( 'canvas' );
						cv.width  = Math.round( iw * k );
						cv.height = Math.round( ih * k );
						var ctx = cv.getContext( '2d' );
						ctx.imageSmoothingEnabled = true;
						ctx.imageSmoothingQuality = 'high';
						ctx.drawImage( image, 0, 0, cv.width, cv.height );
						cv.toBlob( function ( b ) { res( b || file ); }, 'image/jpeg', 0.9 );
					} );
				}

				function destroy() {
					window.removeEventListener( 'resize', reset );
					try { URL.revokeObjectURL( image.src ); } catch ( e ) {}
				}

				return { node: node, master: master, rect: rect, export: exportBlob, destroy: destroy, reset: reset };
			}
		} );
	};
} )();
