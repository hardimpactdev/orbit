export interface SharedProps {
    app: {
        name: string;
        timezone: string;
        locale: string;
    };
    location: {
        current: string;
        previous: string | null;
    };
}
