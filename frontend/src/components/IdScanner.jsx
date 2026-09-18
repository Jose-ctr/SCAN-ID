import { useEffect, useRef, useState } from "react";

function IdScanner({ onCaptured, onClose }) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);

    const [error, setError] = useState("");
    const [starting, setStarting] = useState(true);

    useEffect(() => {
        let mounted = true;

        const startCamera = async () => {
            try {
                if (!navigator.mediaDevices?.getUserMedia) {
                    throw new Error(
                        "Camera access is not supported by this browser."
                    );
                }

                const stream =
                    await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: {
                                ideal: "environment",
                            },
                        },
                        audio: false,
                    });

                if (!mounted) {
                    stream.getTracks().forEach((track) =>
                        track.stop()
                    );
                    return;
                }

                streamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                }

                setStarting(false);
            } catch (cameraError) {
                console.error(cameraError);

                if (mounted) {
                    setStarting(false);
                    setError(
                        "Camera access was denied or is unavailable. Please allow camera permission and try again."
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

        const canvas = document.createElement("canvas");

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        const context = canvas.getContext("2d");

        if (!context) {
            setError("Unable to capture the camera image.");
            return;
        }

        context.drawImage(
            video,
            0,
            0,
            canvas.width,
            canvas.height
        );

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setError("Unable to create the captured image.");
                    return;
                }

                const imageUrl = URL.createObjectURL(blob);

                onCaptured({
                    blob,
                    imageUrl,
                });
            },
            "image/jpeg",
            0.9
        );
    };

    return (
        <section className="action-message">
            <strong>Scan Found ID</strong>

            <p>
                Position the found ID inside the camera view.
                Make sure the document is clear and readable.
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
                    width: "100%",
                    overflow: "hidden",
                    borderRadius: "16px",
                    background: "#000",
                    marginTop: "16px",
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
                        minHeight: "240px",
                        objectFit: "cover",
                    }}
                />
            </div>

            <button
                className="primary-button"
                type="button"
                onClick={captureImage}
                disabled={starting || Boolean(error)}
            >
                Capture ID
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

export default IdScanner;
