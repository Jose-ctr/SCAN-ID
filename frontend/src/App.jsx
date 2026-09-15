import { useState } from "react";

function App() {
    const [activeAction, setActiveAction] = useState(null);

    const handleAction = (action) => {
        setActiveAction(action);
    };

    return (
        <div className="app-shell">
            <header className="app-header">
                <div>
                    <div className="brand">SCAN-ID</div>
                    <div className="brand-subtitle">
                        Lost ID Recovery Network Kenya
                    </div>
                </div>

                <div className="status-badge">
                    Secure
                </div>
            </header>

            <main className="app-content">
                <section className="hero-card">
                    <div className="hero-icon" aria-hidden="true">
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

                <section className="info-grid">
                    <button
                        className="info-card"
                        type="button"
                        onClick={() => handleAction("found")}
                    >
                        <span className="info-icon">+</span>
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
                        <span className="info-icon">✓</span>
                        <strong>Recover My ID</strong>
                        <span>
                            Check a recovery request
                        </span>
                    </button>
                </section>

                <section className="how-it-works">
                    <div className="section-heading">
                        <span>How SCAN-ID works</span>
                    </div>

                    <div className="steps">
                        <div className="step">
                            <span className="step-number">1</span>
                            <div>
                                <strong>Scan</strong>
                                <p>
                                    Scan or enter the found ID details.
                                </p>
                            </div>
                        </div>

                        <div className="step">
                            <span className="step-number">2</span>
                            <div>
                                <strong>Notify</strong>
                                <p>
                                    The verified owner receives an SMS.
                                </p>
                            </div>
                        </div>

                        <div className="step">
                            <span className="step-number">3</span>
                            <div>
                                <strong>Recover</strong>
                                <p>
                                    Complete the secure recovery process
                                    and arrange handover.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {activeAction && (
                    <section className="action-message">
                        <strong>
                            {activeAction === "scan" &&
                                "ID Scanner coming next"}
                            {activeAction === "found" &&
                                "Found ID registration coming next"}
                            {activeAction === "recovery" &&
                                "Recovery lookup coming next"}
                        </strong>

                        <p>
                            The next development stage will connect
                            this interface to the SCAN-ID backend.
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
                <span>Safe • Private • Kenya</span>
            </footer>
        </div>
    );
}

export default App;
