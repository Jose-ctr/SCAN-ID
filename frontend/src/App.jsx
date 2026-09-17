import { useState } from "react";

function App() {
    const [activeAction, setActiveAction] = useState(null);
    const [foundForm, setFoundForm] = useState({
        idType: "",
        idNumber: "",
        location: "",
        phone: "",
    });
    const [recoveryForm, setRecoveryForm] = useState({
        reference: "",
        phone: "",
    });
    const [submitted, setSubmitted] = useState(false);

    const openAction = (action) => {
        setActiveAction(action);
        setSubmitted(false);
    };

    const closeAction = () => {
        setActiveAction(null);
        setSubmitted(false);
    };

    const handleFoundChange = (event) => {
        const { name, value } = event.target;

        setFoundForm((current) => ({
            ...current,
            [name]: value,
        }));
    };

    const handleRecoveryChange = (event) => {
        const { name, value } = event.target;

        setRecoveryForm((current) => ({
            ...current,
            [name]: value,
        }));
    };

    const handleFoundSubmit = (event) => {
        event.preventDefault();
        setSubmitted(true);
    };

    const handleRecoverySubmit = (event) => {
        event.preventDefault();
        setSubmitted(true);
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
                        Help return it to its owner. Start a secure
                        recovery request below.
                    </p>

                    <button
                        className="primary-button"
                        type="button"
                        onClick={() => openAction("scan")}
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
                        onClick={() => openAction("found")}
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
                        onClick={() => openAction("recovery")}
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

                {activeAction === "scan" && (
                    <section
                        className="action-message"
                        aria-live="polite"
                    >
                        <strong>Scan Found ID</strong>

                        <p>
                            Camera scanning will be connected in the
                            next stage. For now, you can report the
                            found ID manually.
                        </p>

                        <button
                            className="primary-button"
                            type="button"
                            onClick={() => openAction("found")}
                        >
                            Report Manually
                        </button>

                        <button
                            className="secondary-button"
                            type="button"
                            onClick={closeAction}
                        >
                            Close
                        </button>
                    </section>
                )}

                {activeAction === "found" && (
                    <section className="action-message">
                        <strong>Report Found ID</strong>

                        {submitted ? (
                            <>
                                <p>
                                    Your found-ID report has been
                                    prepared successfully.
                                </p>

                                <p>
                                    Backend submission and SMS
                                    notification will be connected
                                    in the next development stage.
                                </p>

                                <button
                                    className="secondary-button"
                                    type="button"
                                    onClick={closeAction}
                                >
                                    Done
                                </button>
                            </>
                        ) : (
                            <form onSubmit={handleFoundSubmit}>
                                <label htmlFor="idType">
                                    ID type
                                </label>

                                <select
                                    id="idType"
                                    name="idType"
                                    value={foundForm.idType}
                                    onChange={handleFoundChange}
                                    required
                                >
                                    <option value="">
                                        Select ID type
                                    </option>
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

                                <label htmlFor="idNumber">
                                    ID number
                                </label>

                                <input
                                    id="idNumber"
                                    name="idNumber"
                                    type="text"
                                    value={foundForm.idNumber}
                                    onChange={handleFoundChange}
                                    placeholder="Enter ID number"
                                    autoComplete="off"
                                    required
                                />

                                <label htmlFor="location">
                                    Where was it found?
                                </label>

                                <input
                                    id="location"
                                    name="location"
                                    type="text"
                                    value={foundForm.location}
                                    onChange={handleFoundChange}
                                    placeholder="e.g. Mariakani Stage"
                                    required
                                />

                                <label htmlFor="phone">
                                    Your phone number
                                </label>

                                <input
                                    id="phone"
                                    name="phone"
                                    type="tel"
                                    value={foundForm.phone}
                                    onChange={handleFoundChange}
                                    placeholder="e.g. 07XXXXXXXX"
                                    autoComplete="tel"
                                    required
                                />

                                <button
                                    className="primary-button"
                                    type="submit"
                                >
                                    Prepare Report
                                </button>

                                <button
                                    className="secondary-button"
                                    type="button"
                                    onClick={closeAction}
                                >
                                    Cancel
                                </button>
                            </form>
                        )}
                    </section>
                )}

                {activeAction === "recovery" && (
                    <section className="action-message">
                        <strong>Recover My ID</strong>

                        {submitted ? (
                            <>
                                <p>
                                    Your recovery lookup request has
                                    been prepared.
                                </p>

                                <p>
                                    Live recovery-status lookup will
                                    be connected to the backend next.
                                </p>

                                <button
                                    className="secondary-button"
                                    type="button"
                                    onClick={closeAction}
                                >
                                    Done
                                </button>
                            </>
                        ) : (
                            <form onSubmit={handleRecoverySubmit}>
                                <label htmlFor="reference">
                                    Recovery reference
                                </label>

                                <input
                                    id="reference"
                                    name="reference"
                                    type="text"
                                    value={recoveryForm.reference}
                                    onChange={handleRecoveryChange}
                                    placeholder="Enter recovery reference"
                                    autoComplete="off"
                                    required
                                />

                                <label htmlFor="recovery-phone">
                                    Phone number
                                </label>

                                <input
                                    id="recovery-phone"
                                    name="phone"
                                    type="tel"
                                    value={recoveryForm.phone}
                                    onChange={handleRecoveryChange}
                                    placeholder="e.g. 07XXXXXXXX"
                                    autoComplete="tel"
                                    required
                                />

                                <button
                                    className="primary-button"
                                    type="submit"
                                >
                                    Check Status
                                </button>

                                <button
                                    className="secondary-button"
                                    type="button"
                                    onClick={closeAction}
                                >
                                    Cancel
                                </button>
                            </form>
                        )}
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
