export interface ApiResponse<T> {
    controller: string;
    action: string;
    error: string;
    message: string;
    result: T;
}