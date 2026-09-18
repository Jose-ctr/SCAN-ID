import { useEffect, useRef, useState } from "react";

function IdScanner({ onCaptured, onClose }) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);

    const [error, setError] = useState("");
    const [starting, setStarting] = useState(true);
    const [cameraReady, setCameraReady] = useState(false);

    useEffect(() => {
        let mounted = true;

        const startCamera = async () => {
            try {
                setStarting(true);
                setError("");

                if (!navigator.mediaDevices?.getUserMedia) {
                    throw new Error(
                        "Camera access is not supported by this browser."
                    );
                }

                let stream;

                try {
                    stream =
                        await navigator.mediaDevices.getUserMedia({
                            video: {
                                facingMode: {
                                    ideal: "environment",
                                },
                                width: {
                                    ideal: 1920,
                                },
                                height: {
                                    ideal: 1080,
                                },
                            },
                            audio: false,
                        });
                } catch (firstError) {
                    console.warn(
                        "Preferred camera settings failed. Retrying with basic camera.",
                        firstError
                    );

                    stream =
                        await navigator.mediaDevices.getUserMedia({
                            video: true,
                            audio: false,
                        });
                }

                if (!mounted) {
                    stream
                        .getTracks()
                        .forEach((track) => track.stop());

                    return;
                }

                streamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;

                    try {
                        await videoRef.current.play();
                    } catch (playError) {
                        console.warn(
                            "Camera autoplay was blocked.",
                            playError
                        );
                    }
                }

                setStarting(false);
                setCameraReady(true);
            } catch (cameraError) {
                console.error(cameraError);

                if (!mounted) {
                    return;
                }

                setStarting(false);
                setCameraReady(false);

                if (cameraError?.name === "NotAllowedError") {
                    setError(
                        "Camera permission was denied. Allow camera access and try again."
                    );
                } else if (
                    cameraError?.name === "NotFoundError"
                ) {
                    setError(
                        "No camera was found on this device."
                    );
                } else if (
                    cameraError?.name === "NotReadableError"
                ) {
                    setError(
                        "The camera is being used by another app. Close other camera apps and try again."
                    );
                } else {
                    setError(
                        "Camera access was denied or is unavailable."
                    );
                }
            }
        };

        startCamera();

        return () => {
            mounted = false;

            if (streamRef.current) {
                streamRef.current
                    .getTracks()
                    .forEach((track) => track.stop());

                streamRef.current = null;
            }

            if (videoRef.current) {
                videoRef.current.srcObject = null;
            }
        };
    }, []);

    const captureImage = () => {
        const video = videoRef.current;

        if (!video || video.readyState < 2) {
            setError(
                "Camera is not ready yet. Please wait a moment and try again."
            );
            return;
        }

        const videoWidth = video.videoWidth;
        const videoHeight = video.videoHeight;

        if (!videoWidth || !videoHeight) {
            setError(
                "Camera image is not ready yet. Please try again."
            );
            return;
        }

        /*
         * The guide box uses 82% of the visible camera width.
         * A Kenyan ID card has approximately a 1.586:1
         * width-to-height ratio.
         */

        const guideWidthRatio = 0.82;

        const cropWidth = Math.round(
            videoWidth * guideWidthRatio
        );

        const cropHeight = Math.round(
            cropWidth / 1.586
        );

        const cropX = Math.round(
            (videoWidth - cropWidth) / 2
        );

        const cropY = Math.round(
            (videoHeight - cropHeight) / 2
        );

        const safeCropWidth = Math.min(
            cropWidth,
            videoWidth - cropX
        );

        const safeCropHeight = Math.min(
            cropHeight,
            videoHeight - cropY
        );

        if (
            safeCropWidth <= 0 ||
            safeCropHeight <= 0
        ) {
            setError(
                "Unable to calculate the ID crop. Please try again."
            );
            return;
        }

        const canvas = document.createElement("canvas");

        canvas.width = safeCropWidth;
        canvas.height = safeCropHeight;

        const context = canvas.getContext("2d");

        if (!context) {
            setError(
                "Unable to create the cropped image."
            );
            return;
        }

        context.drawImage(
            video,
            cropX,
            cropY,
            safeCropWidth,
            safeCropHeight,
            0,
            0,
            safeCropWidth,
            safeCropHeight
        );

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setError(
                        "Unable to create the cropped image."
                    );
                    return;
                }

                const imageUrl =
                    URL.createObjectURL(blob);

                onCaptured({
                    blob,
                    imageUrl,
                    cropped: true,
                });
            },
            "image/jpeg",
            0.92
        );
    };

    return (
        <section className="action-message">
            <strong>Scan Found ID</strong>

            <p>
                Fit the entire ID inside the guide box.
                Keep the phone steady and avoid glare.
            </p>

            {starting && (
                <p aria-live="polite">
                    Starting camera…
                </p>
            )}

            {error && (
                <p
                    role="alert"
                    aria-live="assertive"
                >
                    {error}
                </p>
            )}

            <div
                style={{
                    position: "relative",
                    width: "100%",
                    overflow: "hidden",
                    borderRadius: "16px",
                    background: "#000",
                    marginTop: "16px",
                    aspectRatio: "16 / 10",
                }}
            >
                <video
                    ref={videoRef}
                    autoPlay
                    playsInline
                    muted
                    style={{
                        display: "block",
                        width: "100%",
                        height: "100%",
                        objectFit: "cover",
                    }}
                />

                <div
                    style={{
                        position: "absolute",
                        inset: 0,
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "center",
                        pointerEvents: "none",
                    }}
                >
                    <div
                        style={{
                            width: "82%",
                            aspectRatio: "1.586 / 1",
                            border:
                                "3px solid #A6FF80",
                            borderRadius: "14px",
                            boxShadow:
                                "0 0 0 9999px rgba(0,0,0,0.32)",
                            position: "relative",
                        }}
                    >
                        <div
                            style={{
                                position: "absolute",
                                left: "50%",
                                bottom: "-38px",
                                transform:
                                    "translateX(-50%)",
                                background:
                                    "rgba(0,0,0,0.75)",
                                color: "#fff",
                                padding:
                                    "7px 12px",
                                borderRadius: "8px",
                                fontSize: "13px",
                                whiteSpace:
                                    "nowrap",
                            }}
                        >
                            Fit ID inside the box
                        </div>
                    </div>
                </div>
            </div>

            <p
                style={{
                    fontSize: "13px",
                    opacity: 0.75,
                    marginTop: "14px",
                }}
            >
                The captured image will automatically
                be cropped to the guide box.
            </p>

            <button
                className="primary-button"
                type="button"
                onClick={captureImage}
                disabled={
                    starting ||
                    !cameraReady ||
                    Boolean(error)
                }
            >
                Capture & Crop ID
            </button>

            <button
                className="secondary-button"
                type="button"
                onClick={onClose}
            >
                Cancel
            </button>
        </section>
    );
}

export default IdScanner
