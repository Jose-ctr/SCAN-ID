import { useState } from "react";

function App() {
    const [activeAction, setActiveAction] = useState(null);

    const actions = {
        scan: {
            title: "Scan Found ID",
            message:
                "The ID scanner will be connected in the next development stage.",
        },
        found: {
            title: "Report Found ID",
            message:
                "The secure found-ID form will be connected to the backend next.",
        },
        recovery: {
            title: "Recover My ID",
            message:
                "Recovery lookup will allow an owner to check the status of their ID.",
        },
    };

    const handleAction = (action) => {
        setActiveAction(action);
    };

    const currentAction = activeAction
        ? actions[activeAction]
        : null;

    return (
        <div className="app-shell">
            <header className="app-header">
                <div>
                    <div className="brand">SCAN-ID</div>

                    <div className="brand-subtitle">
                        Lost ID Recovery Network Kenya
                    </div>
                </div>

                <div
                    className="status-badge"
                    aria-label="SCAN-ID security status"
                >
                    Secure
                </div>
            </header>

            <main className="app-content">
                <section className="hero-card">
                    <div
                        className="hero-icon"
                        aria-hidden="true"
                    >
                        ID
                    </div>

                    <h1>Found an ID?</h1>

                    <p>
                        Help return it to its owner. Scan the ID,
                        notify the owner by SMS, and arrange a safe
                        handover.
                    </p>

                    <button
                        className="primary-button"
                        type="button"
                        onClick={() => handleAction("scan")}
                    >
                        Scan Found ID
                    </button>
                </section>

                <section
                    className="info-grid"
                    aria-label="SCAN-ID actions"
                >
                    <button
                        className="info-card"
                        type="button"
                        onClick={() => handleAction("found")}
                    >
                        <span
                            className="info-icon"
                            aria-hidden="true"
                        >
                            +
                        </span>

                        <strong>Report Found ID</strong>

                        <span>
                            Enter details manually
                        </span>
                    </button>

                    <button
                        className="info-card"
                        type="button"
                        onClick={() => handleAction("recovery")}
                    >
                        <span
                            className="info-icon"
                            aria-hidden="true"
                        >
                            ✓
                        </span>

                        <strong>Recover My ID</strong>

                        <span>
                            Check a recovery request
                        </span>
                    </button>
                </section>

                <section className="how-it-works">
                    <div className="section-heading">
                        <h2>How SCAN-ID works</h2>
                    </div>

                    <div className="steps">
                        <div className="step">
                            <span
                                className="step-number"
                                aria-hidden="true"
                            >
                                1
                            </span>

                            <div>
                                <strong>Scan</strong>

                                <p>
                                    Scan or enter the found ID details.
                                </p>
                            </div>
                        </div>

                        <div className="step">
                            <span
                                className="step-number"
                                aria-hidden="true"
                            >
                                2
                            </span>

                            <div>
                                <strong>Notify</strong>

                                <p>
                                    The verified owner receives an SMS.
                                </p>
                            </div>
                        </div>

                        <div className="step">
                            <span
                                className="step-number"
                                aria-hidden="true"
                            >
                                3
                            </span>

                            <div>
                                <strong>Recover</strong>

                                <p>
                                    Complete the secure recovery process
                                    and arrange a safe handover.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {currentAction && (
                    <section
                        className="action-message"
                        aria-live="polite"
                    >
                        <strong>
                            {currentAction.title}
                        </strong>

                        <p>
                            {currentAction.message}
                        </p>

                        <button
                            className="secondary-button"
                            type="button"
                            onClick={() => setActiveAction(null)}
                        >
                            Close
                        </button>
                    </section>
                )}
            </main>

            <footer className="app-footer">
                <span>SCAN-ID</span>

                <span>
                    Safe • Private • Kenya
                </span>
            </footer>
        </div>
    );
}

export default App;
