export function Field({ label, htmlFor, error, hint, children, required = false, className = '' }) {
    return (
        <div className={`space-y-1.5 ${className}`}>
            {label ? (
                <label htmlFor={htmlFor} className="cf-label flex items-center gap-1">
                    {label}
                    {required ? <span className="text-danger">*</span> : null}
                </label>
            ) : null}
            {children}
            {error ? (
                <p className="text-xs text-danger" role="alert">
                    {error}
                </p>
            ) : hint ? (
                <p className="text-xs leading-5 text-faint">{hint}</p>
            ) : null}
        </div>
    );
}

export function Input(props) {
    return <input {...props} className={`cf-input ${props.className ?? ''}`} aria-invalid={props.error ? 'true' : undefined} />;
}

export function Select({ children, className = '', ...props }) {
    return (
        <select
            {...props}
            className={`cf-input appearance-none bg-[length:14px] bg-[right_0.75rem_center] bg-no-repeat pr-9 ${className}`}
            style={{
                backgroundImage:
                    "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238b9298' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")",
            }}
        >
            {children}
        </select>
    );
}

export function Textarea(props) {
    return <textarea {...props} className={`cf-input min-h-24 resize-y ${props.className ?? ''}`} />;
}
