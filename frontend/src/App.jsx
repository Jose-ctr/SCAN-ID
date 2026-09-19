import { useEffect, useRef, useState } from "react";

function App() {
    const videoRef = useRef(null);
    const canvasRef = useRef(null);

    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraError, setCameraError] = useState("");
    const [capturedImage, setCapturedImage] = useState("");

    const [form, setForm] = useState({
        idType: "national-id",
        idNumber: "",
        locationFound: "",
        reporterPhone: "",
    });

    const [submitted, setSubmitted] = useState(false);

    const handleChange = (event) => {
        const { name, value } = event.target;

        setForm((current) => ({
            ...current,
            [name]: value,
        }));
    };

    const startCamera = async () => {
        setCameraError("");

        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraError(
                "Camera access is not supported by this browser."
            );
            return;
        }

        try {
            const stream =
                await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: {
                            ideal: "environment",
                        },
                    },
                    audio: false,
                });

            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
            }

            setCameraOpen(true);
        } catch (error) {
            console.error("Camera error:", error);

            setCameraError(
                "Camera access was denied or is unavailable. Please allow camera permission and try again."
            );
        }
    };

    const stopCamera = () => {
        const video = videoRef.current;

        if (video?.srcObject) {
            const tracks = video.srcObject.getTracks();

            tracks.forEach((track) => {
                track.stop();
            });

            video.srcObject = null;
        }

        setCameraOpen(false);
    };

    const captureImage = () => {
        const video = videoRef.current;
        const canvas = canvasRef.current;

        if (!video || !canvas) {
            return;
        }

        if (
            video.videoWidth === 0 ||
            video.videoHeight === 0
        ) {
            setCameraError(
                "Camera is not ready yet. Please wait a moment and try again."
            );
            return;
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        const context = canvas.getContext("2d");

        if (!context) {
            setCameraError(
                "Unable to capture the camera image."
            );
            return;
        }

        context.drawImage(
            video,
            0,
            0,
            canvas.width,
            canvas.height
        );

        const image = canvas.toDataURL(
            "image/jpeg",
            0.85
        );

        setCapturedImage(image);
        stopCamera();
    };

    const clearCapturedImage = () => {
        setCapturedImage("");
    };

    const handleSubmit = (event) => {
        event.preventDefault();
        setSubmitted(true);
    };

    useEffect(() => {
        return () => {
            const video = videoRef.current;

            if (video?.srcObject) {
                video.srcObject
                    .getTracks()
                    .forEach((track) => track.stop());
            }
        };
    }, []);

    return (
        <div className="app-shell">
            <header className="app-header">
                <div className="brand-area">
                    <div className="brand-row">
                        <span className="brand">
                            SCAN-ID
                        </span>

                        <span
                            className="status-badge"
                            aria-label="SCAN-ID secure"
                        >
                            🛡 Secure
                        </span>

                        <span
                            className="kenya-flag"
                            aria-label="Kenya"
                            role="img"
                        >
                            🇰🇪
                        </span>
                    </div>

                    <p className="brand-subtitle">
                        Lost ID Recovery Network Kenya
                    </p>
                </div>
            </header>

            <main className="app-content">
                <section className="how-it-works">
                    <div className="section-heading">
                        <span className="section-label">
                            HOW IT WORKS
                        </span>

                        <h1>Three simple steps</h1>
                    </div>

                    <div className="steps">
                        <article className="step-card">
                            <span className="step-number">
                                1
                            </span>

                            <div>
                                <strong>Scan</strong>

                                <p>
                                    Scan or capture the found ID.
                                </p>
                            </div>
                        </article>

                        <article className="step-card">
                            <span className="step-number">
                                2
                            </span>

                            <div>
                                <strong>Notify</strong>

                                <p>
                                    Notify the verified owner securely.
                                </p>
                            </div>
                        </article>

                        <article className="step-card">
                            <span className="step-number">
                                3
                            </span>

                            <div>
                                <strong>Recover</strong>

                                <p>
                                    Arrange a safe handover.
                                </p>
                            </div>
                        </article>
                    </div>
                </section>

                <section className="scan-card">
                    <div className="section-heading">
                        <span className="section-label">
                            SCAN FOUND ID
                        </span>

                        <h2>
                            Use your camera
                        </h2>

                        <p>
                            Capture the found ID securely.
                            Do not share unnecessary information.
                        </p>
                    </div>

                    {!cameraOpen && !capturedImage && (
                        <button
                            className="primary-button"
                            type="button"
                            onClick={startCamera}
                        >
                            📷 Open Camera
                        </button>
                    )}

                    {cameraOpen && (
                        <div className="camera-area">
                            <video
                                ref={videoRef}
                                className="camera-preview"
                                autoPlay
                                playsInline
                                muted
                            />

                            <div className="camera-actions">
                                <button
                                    className="primary-button"
                                    type="button"
                                    onClick={captureImage}
                                >
                                    Capture ID
                                </button>

                                <button
                                    className="secondary-button"
                                    type="button"
                                    onClick={stopCamera}
                                >
                                    Stop Camera
                                </button>
                            </div>
                        </div>
                    )}

                    {capturedImage && (
                        <div className="captured-area">
                            <img
                                className="captured-image"
                                src={capturedImage}
                                alt="Captured found ID"
                            />

                            <button
                                className="secondary-button"
                                type="button"
                                onClick={clearCapturedImage}
                            >
                                Retake Photo
                            </button>
                        </div>
                    )}

                    {cameraError && (
                        <div
                            className="camera-error"
                            role="alert"
                        >
                            {cameraError}
                        </div>
                    )}

                    <canvas
                        ref={canvasRef}
                        className="hidden-canvas"
                    />
                </section>

                <section className="report-card">
                    <div className="section-heading">
                        <span className="section-label">
                            REPORT FOUND ID
                        </span>

                        <h2>
                            Help return it to its owner
                        </h2>

                        <p>
                            Enter the details below to prepare
                            a secure found-ID report.
                        </p>
                    </div>

                    {submitted ? (
                        <div
                            className="success-message"
                            role="status"
                            aria-live="polite"
                        >
                            <strong>
                                Report prepared
                            </strong>

                            <p>
                                Your found-ID report is ready
                                for secure backend submission.
                            </p>

                            <button
                                className="secondary-button"
                                type="button"
                                onClick={() =>
                                    setSubmitted(false)
                                }
                            >
                                Edit Report
                            </button>
                        </div>
                    ) : (
                        <form onSubmit={handleSubmit}>
                            <div className="form-field">
                                <label htmlFor="idType">
                                    ID type
                                </label>

                                <select
                                    id="idType"
                                    name="idType"
                                    value={form.idType}
                                    onChange={handleChange}
                                    required
                                >
                                    <option value="national-id">
                                        National ID
                                    </option>

                                    <option value="passport">
                                        Passport
                                    </option>

                                    <option value="driving-license">
                                        Driving Licence
                                    </option>

                                    <option value="student-id">
                                        Student ID
                                    </option>

                                    <option value="other">
                                        Other
                                    </option>
                                </select>
                            </div>

                            <div className="form-field">
                                <label htmlFor="idNumber">
                                    ID number
                                </label>

                                <input
                                    id="idNumber"
                                    name="idNumber"
                                    type="text"
                                    value={form.idNumber}
                                    onChange={handleChange}
                                    placeholder="Enter ID number"
                                    autoComplete="off"
                                    required
                                />
                            </div>

                            <div className="form-field">
                                <label htmlFor="locationFound">
                                    Where was it found
                                </label>

                                <input
                                    id="locationFound"
                                    name="locationFound"
                                    type="text"
                                    value={form.locationFound}
                                    onChange={handleChange}
                                    placeholder="e.g. Mariakani Stage"
                                    required
                                />
                            </div>

                            <div className="form-field">
                                <label htmlFor="reporterPhone">
                                    Your phone number
                                </label>

                                <input
                                    id="reporterPhone"
                                    name="reporterPhone"
                                    type="tel"
                                    value={form.reporterPhone}
                                    onChange={handleChange}
                                    placeholder="+254 7__ ______"
                                    autoComplete="tel"
                                    inputMode="tel"
                                    required
                                />
                            </div>

                            <button
                                className="primary-button"
                                type="submit"
                            >
                                Prepare Report
                            </button>
                        </form>
                    )}
                </section>
            </main>

            <footer className="app-footer">
                <span>🔒 Encrypted</span>

                <span>
                    Data Protection Act
                </span>

                <span>
                    Shared only with verified owner
                </span>
            </footer>
        </div>
    );
}

export default App;
